<?php

use PHPUnit\Framework\TestCase;

final class BookPresentationTest extends TestCase {
	public function testAttachesIllustrationsAndPrefersLibraryTranslators(): void {
		if (getenv('FLIBUSTA_DBHOST') === false) {
			self::markTestSkipped('PostgreSQL integration test requires the test stack.');
		}
		$dbh = new PDO(sprintf('pgsql:host=%s;dbname=%s', getenv('FLIBUSTA_DBHOST'), getenv('FLIBUSTA_DBNAME')), getenv('FLIBUSTA_DBUSER'), getenv('FLIBUSTA_DBPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$directory = sys_get_temp_dir() . '/presentation-' . bin2hex(random_bytes(5));
		mkdir($directory);
		$old_books = getenv('FLIBUSTA_BOOKS_DIR');
		putenv('FLIBUSTA_BOOKS_DIR=' . $directory);
		$archive = 'presentation-' . bin2hex(random_bytes(5)) . '.zip';
		$zip = new ZipArchive();
		$zip->open($directory . '/' . $archive, ZipArchive::CREATE);
		$zip->addFromString('99.fb2', '<FictionBook/>');
		$zip->close();
		$dbh->exec('CREATE TEMP TABLE libbook (bookid bigint, filetype text); CREATE TEMP TABLE libfilename (bookid bigint, filename text); CREATE TEMP TABLE book_zip (filename text, usr integer, start_id bigint, end_id bigint)');
		$dbh->exec("INSERT INTO libbook VALUES (99, 'fb2'), (100, 'fb2')");
		$insert_archive = $dbh->prepare("INSERT INTO book_archives (filename, fingerprint, scan_state) VALUES (:filename, 'test', 'complete') RETURNING archive_id");
		$insert_archive->execute([':filename' => $archive]);
		$archive_id = (int)$insert_archive->fetchColumn();
		$insert_entry = $dbh->prepare("INSERT INTO book_archive_entries (archive_id, entry_name, bookid, format, scan_state) VALUES (:archive_id, '99.fb2', 99, 'fb2', 'complete') RETURNING entry_id");
		$insert_entry->execute([':archive_id' => $archive_id]);
		$entry_id = (int)$insert_entry->fetchColumn();
		$dbh->prepare("INSERT INTO book_extracted_metadata (entry_id, illustration_count, translators) VALUES (:entry_id, 0, '[\"Extracted translator\"]')")->execute([':entry_id' => $entry_id]);
		$dbh->exec('CREATE TEMP TABLE libtranslator (bookid bigint, translatorid bigint, pos smallint); CREATE TEMP TABLE libavtorname (avtorid bigint, lastname text, firstname text, middlename text, nickname text)');
		$dbh->exec("INSERT INTO libtranslator VALUES (99, 8, 0); INSERT INTO libavtorname VALUES (8, 'Library', 'Translator', '', '')");

		$books = book_presentation_attach_metadata($dbh, [(object)['bookid' => 99], (object)['bookid' => 100]]);
		self::assertSame(0, $books[0]->illustration_count);
		self::assertSame(['Library Translator'], $books[0]->translator_names);
		self::assertStringContainsString('Иллюстраций: 0', book_presentation_details($books[0]));
		self::assertStringContainsString('неизвестно', book_presentation_details($books[1]));
		// A newer extraction from a lower-priority copy must not replace the chosen file's metadata.
		$other = 'z-' . $archive;
		$zip->open($directory . '/' . $other, ZipArchive::CREATE);
		$zip->addFromString('99.fb2', '<FictionBook/>');
		$zip->close();
		$insert_archive->execute([':filename' => $other]);
		$other_id = (int)$insert_archive->fetchColumn();
		$insert_entry->execute([':archive_id' => $other_id]);
		$other_entry = (int)$insert_entry->fetchColumn();
		$dbh->prepare("INSERT INTO book_extracted_metadata (entry_id, illustration_count, translators, extracted_at) VALUES (?, 9, '[\"Other translator\"]', CURRENT_TIMESTAMP + INTERVAL '1 hour')")->execute([$other_entry]);
		$dbh->exec('DELETE FROM libtranslator');
		$books = book_presentation_attach_metadata($dbh, [(object)['bookid' => 99]]);
		self::assertSame(0, $books[0]->illustration_count);
		self::assertSame(['Extracted translator'], $books[0]->translator_names);
		self::assertSame($archive, book_file_find($dbh, 99)['archive_name']);
		unlink($directory . '/' . $archive);
		$books = book_presentation_attach_metadata($dbh, [(object)['bookid' => 99]]);
		self::assertSame(9, $books[0]->illustration_count);
		self::assertSame($other, book_file_find($dbh, 99)['archive_name']);
		$dbh->prepare('DELETE FROM book_archives WHERE archive_id = :archive_id')->execute([':archive_id' => $archive_id]);
		$dbh->prepare('DELETE FROM book_archives WHERE archive_id = ?')->execute([$other_id]);
		unlink($directory . '/' . $other);
		rmdir($directory);
		putenv($old_books === false ? 'FLIBUSTA_BOOKS_DIR' : 'FLIBUSTA_BOOKS_DIR=' . $old_books);
	}
}
