<?php

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/book_metadata.php';

function book_index_archive_fingerprint(string $path): string {
	$stat = stat($path);
	if ($stat === false) {
		throw new RuntimeException('Cannot stat archive');
	}
	return hash('sha256', implode(':', [$stat['size'], $stat['mtime'], $stat['ino']]));
}

function book_index_archive_entries(string $path, array $limits = []): array {
	$limits = book_file_limits($limits);
	if (filesize($path) === false || filesize($path) > $limits['archive_bytes']) {
		throw new BookFileException('Archive exceeds size limit');
	}
	$zip = new ZipArchive();
	if ($zip->open($path, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
		throw new BookFileException('Invalid or incomplete ZIP archive');
	}
	try {
		$entries = [];
		for ($index = 0; $index < $zip->numFiles; $index++) {
			$stat = $zip->statIndex($index);
			if ($stat === false || !isset($stat['name'], $stat['size'], $stat['crc']) || !book_file_zip_name($stat['name'])) {
				continue;
			}
			if (!preg_match('/^(.+)\.(fb2|epub)$/i', $stat['name'], $match)) {
				continue;
			}
			$bookid = preg_match('/(?:^|\/)([0-9]+)$/', $match[1], $bookid_match) ? (int)$bookid_match[1] : null;
			$entries[] = [
				'entry_name' => $stat['name'],
				'bookid' => $bookid,
				'format' => strtolower($match[2]),
				'size_bytes' => (int)$stat['size'],
				'content_hash' => hash('sha256', $stat['name'] . ':' . $stat['size'] . ':' . $stat['crc']),
			];
		}
		return $entries;
	} finally {
		$zip->close();
	}
}

function book_index_scan_archives(PDO $dbh, string $books_directory, array $limits = []): int {
	$directory = realpath($books_directory);
	if ($directory === false || !is_dir($directory)) {
		throw new RuntimeException('Books directory is unavailable');
	}
	$seen = 0;
	foreach (new DirectoryIterator($directory) as $file) {
		if ($file->isDot() || !$file->isFile() || str_ends_with(strtolower($file->getFilename()), '.part') || strtolower($file->getExtension()) !== 'zip') {
			continue;
		}
		$seen++;
		try {
			$entries = book_index_archive_entries($file->getPathname(), $limits);
		} catch (BookFileException $error) {
			continue;
		}
		$filename = $file->getFilename();
		$fingerprint = book_index_archive_fingerprint($file->getPathname());
		$dbh->beginTransaction();
		try {
			$dbh->prepare('SELECT pg_advisory_xact_lock(hashtext(:filename))')->execute([':filename' => $filename]);
			$archive = $dbh->prepare('SELECT archive_id, fingerprint FROM book_archives WHERE filename = :filename');
			$archive->execute([':filename' => $filename]);
			$current = $archive->fetch(PDO::FETCH_ASSOC);
			$changed = $current === false || $current['fingerprint'] !== $fingerprint;
			if ($current === false) {
				$insert = $dbh->prepare("INSERT INTO book_archives (filename, size_bytes, modified_at, fingerprint, scan_state) VALUES (:filename, :size, to_timestamp(:mtime), :fingerprint, 'pending') RETURNING archive_id");
				$insert->execute([':filename' => $filename, ':size' => $file->getSize(), ':mtime' => $file->getMTime(), ':fingerprint' => $fingerprint]);
				$archive_id = (int)$insert->fetchColumn();
			} else {
				$archive_id = (int)$current['archive_id'];
				if (!$changed) {
					$dbh->commit();
					continue;
				}
				$dbh->prepare("UPDATE book_archives SET size_bytes = :size, modified_at = to_timestamp(:mtime), fingerprint = :fingerprint, scan_state = 'pending', scan_error = NULL, scanned_at = NULL WHERE archive_id = :archive_id")->execute([':size' => $file->getSize(), ':mtime' => $file->getMTime(), ':fingerprint' => $fingerprint, ':archive_id' => $archive_id]);
				$dbh->prepare('DELETE FROM book_extracted_metadata WHERE entry_id IN (SELECT entry_id FROM book_archive_entries WHERE archive_id = :archive_id)')->execute([':archive_id' => $archive_id]);
				$dbh->prepare('DELETE FROM book_archive_entries WHERE archive_id = :archive_id')->execute([':archive_id' => $archive_id]);
			}
			$entry = $dbh->prepare("INSERT INTO book_archive_entries (archive_id, entry_name, bookid, format, size_bytes, content_hash, scan_state) VALUES (:archive_id, :entry_name, :bookid, :format, :size, :content_hash, 'pending') ON CONFLICT (archive_id, entry_name) DO NOTHING");
			foreach ($entries as $value) {
				$entry->execute([':archive_id' => $archive_id, ':entry_name' => $value['entry_name'], ':bookid' => $value['bookid'], ':format' => $value['format'], ':size' => $value['size_bytes'], ':content_hash' => $value['content_hash']]);
			}
			$dbh->prepare("UPDATE book_archives SET scan_state = CASE WHEN EXISTS (SELECT 1 FROM book_archive_entries WHERE archive_id = :archive_id) THEN 'queued' ELSE 'complete' END, scan_error = NULL, scanned_at = CASE WHEN EXISTS (SELECT 1 FROM book_archive_entries WHERE archive_id = :archive_id) THEN NULL ELSE CURRENT_TIMESTAMP END WHERE archive_id = :archive_id")->execute([':archive_id' => $archive_id]);
			$dbh->commit();
		} catch (Throwable $error) {
			$dbh->rollBack();
			throw $error;
		}
	}
	return $seen;
}

function book_index_reconcile_entries(PDO $dbh): int {
	try {
		$statement = $dbh->prepare("UPDATE book_archive_entries entries SET bookid = filename.bookid FROM libfilename filename WHERE entries.bookid IS NULL AND entries.entry_name = filename.filename");
		$statement->execute();
		return $statement->rowCount();
	} catch (PDOException $error) {
		return 0;
	}
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
	require_once dirname(__DIR__) . '/dbinit.php';
	$directories = flibusta_config()['directories'];
	$count = book_index_scan_archives($dbh, $directories['books']);
	book_index_reconcile_entries($dbh);
	echo "Scanned {$count} archives\n";
}
