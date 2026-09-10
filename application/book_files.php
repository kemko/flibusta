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
		foreach ($names as $name) {
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

function book_file_find(PDO $dbh, int $bookid, ?string $books_directory = null, array $limits = []): array {
	$stmt = $dbh->prepare('SELECT b.filetype, f.filename AS libfilename FROM libbook b LEFT JOIN libfilename f USING (bookid) WHERE b.bookid = :bookid LIMIT 1');
	$stmt->execute([':bookid' => $bookid]);
	$book = $stmt->fetch(PDO::FETCH_ASSOC);
	if ($book === false || trim((string)$book['filetype']) === '') {
		throw new BookFileException('Book is unavailable');
	}
	$format = strtolower(trim((string)$book['filetype']));
	$archives = $dbh->prepare('SELECT filename, usr, start_id, end_id FROM book_zip WHERE :bookid BETWEEN start_id AND end_id ORDER BY CASE WHEN usr = :preferred_usr THEN 0 ELSE 1 END, start_id DESC, end_id ASC, filename ASC');
	$archives->execute([
		':bookid' => $bookid,
		':preferred_usr' => $format === 'fb2' ? 0 : 1,
	]);
	$directories = function_exists('flibusta_config') ? flibusta_config()['directories'] : [];
	return book_file_find_in_archives(
		$bookid,
		$format,
		$book['libfilename'] === null ? null : (string)$book['libfilename'],
		$archives->fetchAll(PDO::FETCH_ASSOC),
		$books_directory ?? ($directories['books'] ?? '/application/flibusta'),
		$limits
	);
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
