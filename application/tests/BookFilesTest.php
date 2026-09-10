<?php

use PHPUnit\Framework\TestCase;

final class BookFilesTest extends TestCase {
	private string $directory;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/flibusta-books-' . bin2hex(random_bytes(6));
		mkdir($this->directory);
	}

	protected function tearDown(): void {
		foreach (glob($this->directory . '/*') ?: [] as $file) {
			unlink($file);
		}
		rmdir($this->directory);
	}

	private function archive(string $name, array $entries): void {
		$zip = new ZipArchive();
		self::assertTrue($zip->open($this->directory . '/' . $name, ZipArchive::CREATE) === true);
		foreach ($entries as $entry => $contents) {
			$zip->addFromString($entry, $contents);
		}
		$zip->close();
	}

	public function testFindsCustomNameInFirstArchiveThatActuallyContainsIt(): void {
		$this->archive('first.zip', ['17.epub' => 'standard']);
		$this->archive('second.zip', ['named.epub' => 'custom']);

		$file = book_file_find_in_archives(17, 'epub', 'named.epub', [
			['filename' => 'first.zip'],
			['filename' => 'second.zip'],
		], $this->directory);

		self::assertSame('first.zip', $file['archive_name']);
		self::assertSame('17.epub', $file['entry_name']);
		self::assertSame('standard', book_file_contents($file));
	}

	public function testUsesLaterOverlappingArchiveOnlyWhenEarlierOneHasNoCandidate(): void {
		$this->archive('a.zip', ['unrelated.txt' => 'no']);
		$this->archive('b.zip', ['18.fb2' => 'book']);

		$file = book_file_find_in_archives(18, 'fb2', null, [
			['filename' => 'a.zip'],
			['filename' => 'b.zip'],
		], $this->directory);

		self::assertSame('b.zip', $file['archive_name']);
		self::assertSame('book', book_file_contents($file));
	}

	public function testRejectsUnsafeNamesAndOversizedEntries(): void {
		$this->archive('safe.zip', ['19.fb2' => str_repeat('x', 8)]);
		$this->expectException(BookFileException::class);
		book_file_find_in_archives(19, 'fb2', '../19.fb2', [['filename' => 'safe.zip']], $this->directory, ['entry_bytes' => 4]);
	}

	public function testRejectsUnsafeArchiveAndEntryPaths(): void {
		$this->archive('safe.zip', ['nested/21.fb2' => 'book']);

		self::assertFalse(book_file_zip_name('../21.fb2'));
		self::assertFalse(book_file_zip_name('/21.fb2'));
		self::assertFalse(book_file_zip_name('nested\\21.fb2'));
		$this->expectException(BookFileException::class);
		book_file_find_in_archives(21, 'fb2', 'nested/../21.fb2', [['filename' => '../safe.zip']], $this->directory);
	}

	public function testLooksUpDatabaseRecordsUsingDeterministicArchiveOrder(): void {
		if (getenv('FLIBUSTA_DBHOST') === false) {
			self::markTestSkipped('PostgreSQL integration test requires the test stack.');
		}
		$this->archive('range.zip', ['custom.epub' => 'epub']);
		$dbh = new PDO(sprintf('pgsql:host=%s;dbname=%s', getenv('FLIBUSTA_DBHOST'), getenv('FLIBUSTA_DBNAME')), getenv('FLIBUSTA_DBUSER'), getenv('FLIBUSTA_DBPASSWORD'));
		$dbh->exec('CREATE TEMP TABLE libbook (bookid bigint PRIMARY KEY, filetype varchar(16)); CREATE TEMP TABLE libfilename (bookid bigint PRIMARY KEY, filename text); CREATE TEMP TABLE book_zip (filename text, usr integer, start_id bigint, end_id bigint)');
		$dbh->exec("INSERT INTO libbook VALUES (20, 'epub'); INSERT INTO libfilename VALUES (20, 'custom.epub'); INSERT INTO book_zip VALUES ('range.zip', 1, 1, 100)");

		$file = book_file_find($dbh, 20, $this->directory);

		self::assertSame('range.zip', $file['archive_name']);
		self::assertSame('custom.epub', $file['entry_name']);
	}

	public function testResolvesNewNestedEntriesWithoutLegacyRangeRefresh(): void {
		$dbh = new PDO(sprintf('pgsql:host=%s;dbname=%s', getenv('FLIBUSTA_DBHOST'), getenv('FLIBUSTA_DBNAME')), getenv('FLIBUSTA_DBUSER'), getenv('FLIBUSTA_DBPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$dbh->exec('CREATE TEMP TABLE libbook (bookid bigint, filetype text); CREATE TEMP TABLE libfilename (bookid bigint, filename text); CREATE TEMP TABLE book_zip (filename text, usr integer, start_id bigint, end_id bigint)');
		$dbh->exec("INSERT INTO libbook VALUES (42, 'fb2')");
		$this->archive('new-nested.zip', ['nested/42.fb2' => '<FictionBook/>']);
		book_index_scan_archives($dbh, $this->directory);
		$file = book_file_find($dbh, 42, $this->directory);
		self::assertSame('nested/42.fb2', $file['entry_name']);
		self::assertSame('<FictionBook/>', book_file_contents($file));
		self::assertSame(0, (int)$dbh->query('SELECT count(*) FROM book_zip')->fetchColumn());
		self::assertSame([], book_file_find_many($dbh, []));
		self::assertSame([], book_file_find_many($dbh, [9999], $this->directory));
		$dbh->exec("DELETE FROM book_archives WHERE filename = 'new-nested.zip'");
	}
}
