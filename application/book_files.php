<?php

class BookFileException extends RuntimeException {
}

function book_file_limits(array $limits = []): array {
	$config_limits = function_exists('flibusta_config') ? flibusta_config()['limits'] : [];
	return [
		'archive_bytes' => (int)($limits['archive_bytes'] ?? $config_limits['archive_bytes'] ?? 1073741824),
		'entry_bytes' => (int)($limits['entry_bytes'] ?? $config_limits['entry_bytes'] ?? 104857600),
	];
}

function book_file_zip_name(string $name): bool {
	if ($name === '' || str_contains($name, "\0") || str_starts_with($name, '/') || str_contains($name, '\\')) {
		return false;
	}
	foreach (explode('/', $name) as $part) {
		if ($part === '' || $part === '.' || $part === '..') {
			return false;
		}
	}
	return true;
}

function book_file_archive_path(string $books_directory, string $archive_name, int $archive_limit): string {
	if (basename($archive_name) !== $archive_name || !str_ends_with(strtolower($archive_name), '.zip')) {
		throw new BookFileException('Invalid archive name');
	}
	$base = realpath($books_directory);
	$path = realpath($books_directory . DIRECTORY_SEPARATOR . $archive_name);
	if ($base === false || $path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR) || !is_file($path)) {
		throw new BookFileException('Archive is unavailable');
	}
	$size = filesize($path);
	if ($size === false || $size > $archive_limit) {
		throw new BookFileException('Archive exceeds size limit');
	}
	return $path;
}

function book_file_zip_contents(ZipArchive $zip, string $entry_name, int $entry_limit): string {
	if (!book_file_zip_name($entry_name)) {
		throw new BookFileException('Invalid archive entry name');
	}
	$stat = $zip->statName($entry_name);
	if ($stat === false || !isset($stat['size']) || $stat['size'] > $entry_limit) {
		throw new BookFileException('Archive entry is unavailable or exceeds size limit');
	}
	$data = $zip->getFromName($entry_name);
	if ($data === false || strlen($data) > $entry_limit) {
		throw new BookFileException('Cannot read archive entry');
	}
	return $data;
}

function book_file_find_in_archives(int $bookid, string $format, ?string $libfilename, array $archives, string $books_directory, array $limits = []): array {
	if ($bookid < 1 || !preg_match('/^[a-z0-9]+$/i', $format)) {
		throw new BookFileException('Invalid book identifier or format');
	}
	$limits = book_file_limits($limits);
	$names = [];
	if ($libfilename !== null && book_file_zip_name($libfilename)) {
		$names[] = $libfilename;
	}
	$names[] = $bookid . '.' . strtolower($format);
	$names = array_values(array_unique($names));

	foreach ($archives as $archive) {
		try {
			$path = book_file_archive_path($books_directory, (string)$archive['filename'], $limits['archive_bytes']);
		} catch (BookFileException $error) {
			continue;
		}
		$zip = new ZipArchive();
		if ($zip->open($path, ZipArchive::RDONLY) !== true) {
			continue;
		}
		$entry_names = array_values(array_unique(array_merge($names, $archive['entry_names'] ?? [])));
		foreach ($entry_names as $name) {
			if (!book_file_zip_name($name)) {
				continue;
			}
			$stat = $zip->statName($name);
			if ($stat !== false && isset($stat['size']) && $stat['size'] <= $limits['entry_bytes']) {
				$zip->close();
				return [
					'bookid' => $bookid,
					'format' => strtolower($format),
					'archive_path' => $path,
					'archive_name' => $archive['filename'],
					'entry_name' => $name,
					'entry_bytes' => (int)$stat['size'],
				];
			}
		}
		$zip->close();
	}
	throw new BookFileException('Book file is unavailable');
}

function book_file_find_many(PDO $dbh, array $bookids, ?string $books_directory = null, array $limits = []): array {
	$bookids = array_values(array_unique(array_filter(array_map('intval', $bookids), static fn (int $id): bool => $id > 0)));
	if ($bookids === []) {
		return [];
	}
	$in = implode(',', $bookids);
	$books = $dbh->query("SELECT b.bookid, b.filetype, f.filename AS libfilename FROM libbook b LEFT JOIN libfilename f USING (bookid) WHERE b.bookid IN ({$in})")->fetchAll(PDO::FETCH_ASSOC);
	// Keep the legacy range priority, adding actual indexed entries even without a range row.
	$rows = $dbh->query("WITH candidates AS (
		SELECT b.bookid, z.filename, z.usr, z.start_id, z.end_id, NULL::text AS entry_name
		FROM libbook b JOIN book_zip z ON b.bookid BETWEEN z.start_id AND z.end_id WHERE b.bookid IN ({$in})
		UNION ALL
		SELECT b.bookid, a.filename, COALESCE(z.usr, CASE WHEN lower(trim(b.filetype)) = 'fb2' THEN 0 ELSE 1 END),
			COALESCE(z.start_id, 0), COALESCE(z.end_id, 9223372036854775807), e.entry_name
		FROM libbook b JOIN book_archive_entries e ON e.bookid = b.bookid AND e.format = lower(trim(b.filetype))
		JOIN book_archives a USING (archive_id) LEFT JOIN book_zip z ON z.filename = a.filename
		WHERE b.bookid IN ({$in})
	) SELECT c.* FROM candidates c JOIN libbook b USING (bookid)
	ORDER BY c.bookid, CASE WHEN c.usr = CASE WHEN lower(trim(b.filetype)) = 'fb2' THEN 0 ELSE 1 END THEN 0 ELSE 1 END,
		c.start_id DESC, c.end_id ASC, c.filename, c.entry_name")->fetchAll(PDO::FETCH_ASSOC);
	$archives = [];
	foreach ($rows as $row) {
		$archive = &$archives[(int)$row['bookid']][$row['filename']];
		$archive['filename'] = $row['filename'];
		if ($row['entry_name'] !== null) {
			$archive['entry_names'][] = $row['entry_name'];
		}
		unset($archive);
	}
	$directories = function_exists('flibusta_config') ? flibusta_config()['directories'] : [];
	$result = [];
	foreach ($books as $book) {
		$bookid = (int)$book['bookid'];
		try {
			$result[$bookid] = book_file_find_in_archives($bookid, strtolower(trim((string)$book['filetype'])), $book['libfilename'],
				array_values($archives[$bookid] ?? []), $books_directory ?? ($directories['books'] ?? '/application/flibusta'), $limits);
		} catch (BookFileException $error) {
			// Missing files remain unavailable; callers may still render their catalog entries.
		}
	}
	return $result;
}

function book_file_find(PDO $dbh, int $bookid, ?string $books_directory = null, array $limits = []): array {
	$files = book_file_find_many($dbh, [$bookid], $books_directory, $limits);
	return $files[$bookid] ?? throw new BookFileException('Book file is unavailable');
}

function book_file_contents(array $book_file, array $limits = []): string {
	$limits = book_file_limits($limits);
	$path = book_file_archive_path(dirname((string)$book_file['archive_path']), (string)$book_file['archive_name'], $limits['archive_bytes']);
	$zip = new ZipArchive();
	if ($zip->open($path, ZipArchive::RDONLY) !== true) {
		throw new BookFileException('Cannot open archive');
	}
	try {
		return book_file_zip_contents($zip, (string)$book_file['entry_name'], $limits['entry_bytes']);
	} finally {
		$zip->close();
	}
}
