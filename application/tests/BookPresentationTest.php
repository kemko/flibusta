<?php

use PHPUnit\Framework\TestCase;

final class BookPresentationTest extends TestCase {
	public function testAttachesIllustrationsAndPrefersLibraryTranslators(): void {
		if (getenv('FLIBUSTA_DBHOST') === false) {
			self::markTestSkipped('PostgreSQL integration test requires the test stack.');
		}
		$dbh = new PDO(sprintf('pgsql:host=%s;dbname=%s', getenv('FLIBUSTA_DBHOST'), getenv('FLIBUSTA_DBNAME')), getenv('FLIBUSTA_DBUSER'), getenv('FLIBUSTA_DBPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$archive = 'presentation-' . bin2hex(random_bytes(5)) . '.zip';
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
		$dbh->prepare('DELETE FROM book_archives WHERE archive_id = :archive_id')->execute([':archive_id' => $archive_id]);
	}
}
