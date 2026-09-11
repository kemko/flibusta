<?php

use PHPUnit\Framework\TestCase;

final class BookIndexTest extends TestCase {
	private string $directory;
	private ?PDO $dbh = null;

	protected function setUp(): void {
		if (getenv('FLIBUSTA_DBHOST') === false) {
			self::markTestSkipped('PostgreSQL integration test requires the test stack.');
		}
		$this->directory = sys_get_temp_dir() . '/flibusta-index-' . bin2hex(random_bytes(6));
		mkdir($this->directory);
		$this->dbh = new PDO(sprintf('pgsql:host=%s;dbname=%s', getenv('FLIBUSTA_DBHOST'), getenv('FLIBUSTA_DBNAME')), getenv('FLIBUSTA_DBUSER'), getenv('FLIBUSTA_DBPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$this->dbh->exec('DELETE FROM book_extracted_metadata; DELETE FROM book_archive_entries; DELETE FROM book_archives');
	}

	protected function tearDown(): void {
		foreach (glob($this->directory . '/*') ?: [] as $path) {
			unlink($path);
		}
		if (isset($this->directory)) {
			rmdir($this->directory);
		}
	}

	private function archive(string $name, array $entries): void {
		$zip = new ZipArchive();
		self::assertTrue($zip->open($this->directory . '/' . $name, ZipArchive::CREATE) === true);
		foreach ($entries as $entry => $contents) {
			$zip->addFromString($entry, $contents);
		}
		$zip->close();
	}

	public function testLegacyZipIndexSkipsNonRangeNamesAndStillScansTheirContents(): void {
		$this->dbh->exec('CREATE TABLE IF NOT EXISTS book_zip (filename text, start_id bigint, end_id bigint, usr smallint)');
		foreach (['f.fb2-000001-000020.zip', 'f.n.000021-000030.zip', 'f.fb2-700000-788888_lost.zip', 'f.fb2-1-999999999999999999999.zip', 'f.fb2-30-20.zip'] as $name) {
			$this->archive($name, ['42.fb2' => '<FictionBook/>']);
		}
		mkdir($this->directory . '/f.fb2-40-50.zip');
		try {
			$command = 'FLIBUSTA_BOOKS_DIR=' . escapeshellarg($this->directory) . ' ' . escapeshellarg(PHP_BINARY)
				. ' ' . escapeshellarg(dirname(__DIR__) . '/tools/app_update_zip_list.php') . ' 2>&1';
			exec($command, $output, $status);
			self::assertSame(0, $status, implode("\n", $output));
			$rows = $this->dbh->query('SELECT filename, start_id, end_id, usr FROM book_zip ORDER BY start_id')->fetchAll(PDO::FETCH_ASSOC);
			self::assertCount(2, $rows);
			self::assertSame('f.fb2-000001-000020.zip', $rows[0]['filename']);
			self::assertSame(1, (int)$rows[0]['start_id']);
			self::assertSame(20, (int)$rows[0]['end_id']);
			self::assertSame(0, (int)$rows[0]['usr']);
			self::assertSame(1, (int)$rows[1]['usr']);
			self::assertSame(5, (int)$this->dbh->query('SELECT count(*) FROM book_archives')->fetchColumn());
		} finally {
			rmdir($this->directory . '/f.fb2-40-50.zip');
			$this->dbh->exec('DELETE FROM book_zip');
		}
	}

	public function testScansOnlyCompleteArchivesAndDoesNotReparseSuccessfulEntries(): void {
		$this->archive('books.zip', ['42.fb2' => file_get_contents(__DIR__ . '/fixtures/books/namespaced.fb2')]);
		file_put_contents($this->directory . '/upload.zip.part', 'incomplete');
		file_put_contents($this->directory . '/broken.zip', 'not a zip');

		self::assertSame(2, book_index_scan_archives($this->dbh, $this->directory));
		self::assertFalse((bool)$this->dbh->query("SELECT EXISTS (SELECT 1 FROM book_archives WHERE filename = 'broken.zip')")->fetchColumn());
		self::assertFalse((bool)$this->dbh->query("SELECT EXISTS (SELECT 1 FROM book_archives WHERE filename = 'upload.zip.part')")->fetchColumn());
		self::assertSame(1, book_index_run_worker($this->dbh, $this->directory));
		$entry = $this->dbh->query("SELECT entry_id, scan_state FROM book_archive_entries WHERE bookid = 42")->fetch(PDO::FETCH_ASSOC);
		self::assertSame('complete', $entry['scan_state']);
		$extracted = $this->dbh->prepare('SELECT extracted_at FROM book_extracted_metadata WHERE entry_id = :entry_id');
		$extracted->execute([':entry_id' => $entry['entry_id']]);
		$first_extracted_at = $extracted->fetchColumn();

		self::assertSame(2, book_index_scan_archives($this->dbh, $this->directory));
		self::assertSame(0, book_index_run_worker($this->dbh, $this->directory));
		$extracted->execute([':entry_id' => $entry['entry_id']]);
		self::assertSame($first_extracted_at, $extracted->fetchColumn());
	}

	public function testFailedArchiveInsertionRollsBackAndCanBeRetried(): void {
		$this->archive('interrupted.zip', ['1.fb2' => '<FictionBook/>', '2.fb2' => '<FictionBook/>']);
		$this->dbh->exec("ALTER TABLE book_archive_entries ADD CONSTRAINT review_failure CHECK (entry_name <> '2.fb2')");
		try {
			try {
				book_index_scan_archives($this->dbh, $this->directory);
				self::fail('The injected entry failure must abort the archive');
			} catch (PDOException $error) {
				self::assertSame(0, (int)$this->dbh->query('SELECT count(*) FROM book_archives')->fetchColumn());
				self::assertSame(0, (int)$this->dbh->query('SELECT count(*) FROM book_archive_entries')->fetchColumn());
			}
		} finally {
			$this->dbh->exec('ALTER TABLE book_archive_entries DROP CONSTRAINT review_failure');
		}
		book_index_scan_archives($this->dbh, $this->directory);
		self::assertSame(2, (int)$this->dbh->query('SELECT count(*) FROM book_archive_entries')->fetchColumn());
	}

	public function testRecordsAndSeparatelyRetriesMetadataErrors(): void {
		$this->archive('bad-book.zip', ['9.fb2' => '<broken']);
		book_index_scan_archives($this->dbh, $this->directory);
		self::assertSame(1, book_index_run_worker($this->dbh, $this->directory));
		self::assertSame('error', $this->dbh->query('SELECT scan_state FROM book_archive_entries WHERE bookid = 9')->fetchColumn());
		self::assertSame(1, book_index_run_worker($this->dbh, $this->directory, 50, true));
		self::assertNotFalse($this->dbh->query('SELECT extraction_error FROM book_extracted_metadata LIMIT 1')->fetchColumn());
	}

	public function testReconcilesLateFilenameMappingAndResumesInterruptedEntry(): void {
		$this->archive('custom.zip', ['late-name.fb2' => '<FictionBook/>']);
		book_index_scan_archives($this->dbh, $this->directory);
		$this->dbh->exec('CREATE TEMP TABLE libfilename (bookid bigint, filename text)');
		$this->dbh->exec("INSERT INTO libfilename VALUES (77, 'late-name.fb2')");
		self::assertSame(1, book_index_reconcile_entries($this->dbh));
		self::assertSame(77, $this->dbh->query("SELECT bookid FROM book_archive_entries WHERE entry_name = 'late-name.fb2'")->fetchColumn());

		$claimed = book_index_claim_entry($this->dbh);
		self::assertNotNull($claimed);
		self::assertNull(book_index_claim_entry($this->dbh));
		$this->dbh->exec("UPDATE book_archive_entries SET scanned_at = CURRENT_TIMESTAMP - INTERVAL '901 seconds' WHERE entry_id = " . (int)$claimed['entry_id']);
		self::assertSame((int)$claimed['entry_id'], (int)book_index_claim_entry($this->dbh)['entry_id']);
	}
}
