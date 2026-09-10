<?php

require_once __DIR__ . '/app_scan_books.php';

function book_index_claim_entry(PDO $dbh, bool $retry_errors = false, int $stale_seconds = 900, array $excluded_entry_ids = []): ?array {
	$states = $retry_errors ? "e.scan_state = 'error'" : "(e.scan_state = 'pending' OR (e.scan_state = 'processing' AND e.scanned_at < CURRENT_TIMESTAMP - (:stale || ' seconds')::interval))";
	$exclude = '';
	$parameters = $retry_errors ? [] : [':stale' => $stale_seconds];
	foreach ($excluded_entry_ids as $index => $entry_id) {
		$key = ':excluded' . $index;
		$exclude .= ($exclude === '' ? ' AND e.entry_id NOT IN (' : ', ') . $key;
		$parameters[$key] = $entry_id;
	}
	if ($exclude !== '') {
		$exclude .= ')';
	}
	$dbh->beginTransaction();
	try {
		$statement = $dbh->prepare("SELECT e.entry_id, e.entry_name, e.format, a.filename FROM book_archive_entries e JOIN book_archives a USING (archive_id) WHERE {$states}{$exclude} ORDER BY e.entry_id FOR UPDATE OF e SKIP LOCKED LIMIT 1");
		$statement->execute($parameters);
		$entry = $statement->fetch(PDO::FETCH_ASSOC);
		if ($entry === false) {
			$dbh->commit();
			return null;
		}
		$dbh->prepare("UPDATE book_archive_entries SET scan_state = 'processing', scan_error = NULL, scanned_at = CURRENT_TIMESTAMP WHERE entry_id = :entry_id")->execute([':entry_id' => $entry['entry_id']]);
		$dbh->commit();
		return $entry;
	} catch (Throwable $error) {
		if ($dbh->inTransaction()) {
			$dbh->rollBack();
		}
		throw $error;
	}
}

function book_index_process_entry(PDO $dbh, array $entry, string $books_directory, array $limits = []): void {
	try {
		$book_file = [
			'archive_path' => $books_directory . '/' . $entry['filename'],
			'archive_name' => $entry['filename'],
			'entry_name' => $entry['entry_name'],
			'format' => $entry['format'],
		];
		$metadata = book_metadata_for_file($book_file, $limits);
		$save = $dbh->prepare("INSERT INTO book_extracted_metadata (entry_id, illustration_count, translators, metadata, extracted_at, extraction_error) VALUES (:entry_id, :illustrations, CAST(:translators AS jsonb), '{}'::jsonb, CURRENT_TIMESTAMP, NULL) ON CONFLICT (entry_id) DO UPDATE SET illustration_count = EXCLUDED.illustration_count, translators = EXCLUDED.translators, metadata = EXCLUDED.metadata, extracted_at = EXCLUDED.extracted_at, extraction_error = NULL");
		$save->execute([':entry_id' => $entry['entry_id'], ':illustrations' => $metadata['illustration_count'], ':translators' => json_encode($metadata['translators'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
		$dbh->prepare("UPDATE book_archive_entries SET scan_state = 'complete', scan_error = NULL, scanned_at = CURRENT_TIMESTAMP WHERE entry_id = :entry_id")->execute([':entry_id' => $entry['entry_id']]);
		$dbh->prepare("UPDATE book_archives archive SET scan_state = 'complete', scan_error = NULL, scanned_at = CURRENT_TIMESTAMP WHERE archive.filename = :filename AND NOT EXISTS (SELECT 1 FROM book_archive_entries entries WHERE entries.archive_id = archive.archive_id AND entries.scan_state <> 'complete')")->execute([':filename' => $entry['filename']]);
	} catch (Throwable $error) {
		$dbh->prepare("INSERT INTO book_extracted_metadata (entry_id, metadata, extraction_error) VALUES (:entry_id, '{}'::jsonb, :error) ON CONFLICT (entry_id) DO UPDATE SET extraction_error = EXCLUDED.extraction_error, extracted_at = CURRENT_TIMESTAMP")->execute([':entry_id' => $entry['entry_id'], ':error' => $error->getMessage()]);
		$dbh->prepare("UPDATE book_archive_entries SET scan_state = 'error', scan_error = :error, scanned_at = CURRENT_TIMESTAMP WHERE entry_id = :entry_id")->execute([':error' => $error->getMessage(), ':entry_id' => $entry['entry_id']]);
		$dbh->prepare("UPDATE book_archives SET scan_state = 'error', scan_error = :error, scanned_at = CURRENT_TIMESTAMP WHERE filename = :filename")->execute([':error' => $error->getMessage(), ':filename' => $entry['filename']]);
	}
}

function book_index_run_worker(PDO $dbh, string $books_directory, int $limit = 50, bool $retry_errors = false, array $limits = []): int {
	$done = 0;
	$attempted = [];
	while ($done < $limit && ($entry = book_index_claim_entry($dbh, $retry_errors, (int)($limits['job_seconds'] ?? 900), $attempted)) !== null) {
		book_index_process_entry($dbh, $entry, $books_directory, $limits);
		$attempted[] = (int)$entry['entry_id'];
		$done++;
	}
	return $done;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
	require_once dirname(__DIR__) . '/dbinit.php';
	$config = flibusta_config();
	$count = book_index_run_worker($dbh, $config['directories']['books'], 50, in_array('--retry-errors', $argv, true), $config['limits']);
	echo "Processed {$count} entries\n";
}
