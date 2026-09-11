<?php
error_reporting(E_ALL);
require_once dirname(__DIR__) . '/dbinit.php';
require_once __DIR__ . '/app_scan_books.php';

if ($handle = opendir(flibusta_config()['directories']['books'])) {
	$dbh->beginTransaction();
	$dbh->exec("TRUNCATE book_zip");
	$stmt = $dbh->prepare("INSERT INTO book_zip (filename, start_id, end_id, usr) VALUES (:fn, :start, :end, :usr)");

	while (false !== ($entry = readdir($handle))) {
		// Only conventional range names belong in the legacy index; other ZIPs are scanned below.
		if (!is_file(flibusta_config()['directories']['books'] . '/' . $entry)
			|| !preg_match('/^[fd]\.(fb2|n)[.-]([0-9]+)-([0-9]+)\.zip$/D', $entry, $range)
			|| str_starts_with($entry, 'd.fb2-009')) {
			continue;
		}
		$start = filter_var(ltrim($range[2], '0') ?: '0', FILTER_VALIDATE_INT);
		$end = filter_var(ltrim($range[3], '0') ?: '0', FILTER_VALIDATE_INT);
		if ($start === false || $end === false || $start > $end) {
			continue;
		}
		$stmt->execute([':fn' => $entry, ':start' => $start, ':end' => $end, ':usr' => $range[1] === 'fb2' ? 0 : 1]);
	}
	$dbh->commit();
	closedir($handle);
}

book_index_scan_archives($dbh, flibusta_config()['directories']['books']);
book_index_reconcile_entries($dbh);
