<?php

use PHPUnit\Framework\TestCase;

final class AuthorSearchTest extends TestCase {
	private ?PDO $dbh = null;

	protected function setUp(): void {
		if (getenv('FLIBUSTA_DBHOST') === false) {
			self::markTestSkipped('PostgreSQL integration test requires the test stack.');
		}
		$this->dbh = new PDO(sprintf('pgsql:host=%s;dbname=%s', getenv('FLIBUSTA_DBHOST'), getenv('FLIBUSTA_DBNAME')), getenv('FLIBUSTA_DBUSER'), getenv('FLIBUSTA_DBPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$this->dbh->exec('DELETE FROM author_search_index');
		$this->dbh->exec("CREATE TEMP TABLE libavtorname (avtorid bigint PRIMARY KEY, lastname text NOT NULL DEFAULT '', firstname text NOT NULL DEFAULT '', middlename text NOT NULL DEFAULT '', nickname text NOT NULL DEFAULT '', masterid bigint NOT NULL DEFAULT 0);
			CREATE TEMP TABLE libavtoraliase (badid bigint NOT NULL, goodid bigint NOT NULL);
			CREATE TEMP TABLE libbook (bookid bigint PRIMARY KEY, title text, deleted varchar(1) NOT NULL DEFAULT '0');
			CREATE TEMP TABLE libavtor (bookid bigint NOT NULL, avtorid bigint NOT NULL);");
		$this->dbh->exec("INSERT INTO libavtorname (avtorid, lastname, firstname, masterid) VALUES
			(1, 'Герберт', 'Фрэнк', 0), (2, 'Герберт', 'Френк', 1),
			(3, 'Смит', 'Джон', 0), (4, 'Смит', 'Джеймс', 0),
			(5, 'Цикл', 'А', 6), (6, 'Цикл', 'Б', 5), (7, 'Пропал', 'Мастер', 99);
			INSERT INTO libbook (bookid, title) VALUES (10, 'Дюна'), (11, 'Мессия Дюны'), (12, 'Смит');
			INSERT INTO libavtor (bookid, avtorid) VALUES (10, 1), (11, 2), (12, 3), (12, 4);");
		author_search_rebuild($this->dbh);
	}

	public function testNormalizesNamesAndResolvesChainsSafely(): void {
		self::assertSame('фрэнк герберт', author_search_normalize(" Фрэнк\n Герберт "));
		self::assertSame('елкин', author_search_normalize('Ёлкин'));
		$authors = [
			1 => (object)['masterid' => 2],
			2 => (object)['masterid' => 3],
			3 => (object)['masterid' => 0],
			4 => (object)['masterid' => 5],
			5 => (object)['masterid' => 4],
			6 => (object)['masterid' => 99],
		];
		self::assertSame(3, author_search_canonical_id($authors, 1));
		self::assertSame(4, author_search_canonical_id($authors, 5));
		self::assertSame(6, author_search_canonical_id($authors, 6));
	}

	public function testFindsAliasesVariantsAndKeepsNamesakesSeparate(): void {
		foreach (['Фрэнк Герберт', 'Герберт Фрэнк', 'Френк Герберт', '  ГЕРБЕРТ   ФРЕНК  '] as $query) {
			$results = author_search_results($this->dbh, $query);
			self::assertSame(1, (int)$results[0]->author_id);
			self::assertSame(2, (int)$results[0]->book_count);
		}
		$smiths = author_search_results($this->dbh, 'Смит');
		self::assertSame([3, 4], array_map(static fn($result): int => (int)$result->author_id, $smiths));
		self::assertSame(2, author_search_count($this->dbh, 'Смит'));
		self::assertSame([1, 2], author_search_linked_ids($this->dbh, 2)[1]);
		self::assertSame(1, (int)author_search_results($this->dbh, 'Фрэнк Герберт')[0]->exact_match);
		self::assertSame(1, (int)author_search_results($this->dbh, 'Фрэнк Гербер')[0]->author_id);
	}

	public function testUsesParametersAndTrigramIndex(): void {
		$placeholders = author_search_placeholders([2, 7], 'author_');
		self::assertSame(':author_0, :author_1', $placeholders['sql']);
		self::assertSame([':author_0' => 2, ':author_1' => 7], $placeholders['parameters']);
		$this->dbh->exec("INSERT INTO author_search_index (author_id, canonical_author_id, normalized_name, source)
			SELECT 100000 + value, 100000 + value, md5(value::text), 'synthetic' FROM generate_series(1, 50000) AS value");
		$this->dbh->exec('ANALYZE author_search_index');
		$this->dbh->exec('SET enable_seqscan = off');
		$plan = $this->dbh->query("EXPLAIN SELECT author_id FROM author_search_index WHERE normalized_name % 'cfa0860e83a4c3a763a7e62d825349f7'")->fetchAll(PDO::FETCH_COLUMN);
		self::assertStringContainsString('author_search_index_name_trgm_idx', implode("\n", $plan));
	}

	public function testCleanupAndImportPreserveAndRebuildAuthorData(): void {
		$cleanup = file_get_contents(dirname(__DIR__) . '/tools/cleanup_db.sql');
		self::assertStringContainsString('libavtoraliase', $cleanup);
		self::assertStringContainsString('libtranslator', $cleanup);
		self::assertStringContainsString('libavtorname.masterid = 0', $cleanup);
		self::assertStringContainsString('child.masterid', $cleanup);
		self::assertStringContainsString('php /application/author_search.php', file_get_contents(dirname(__DIR__) . '/tools/app_import_sql.sh'));
		self::assertStringContainsString('/application/tools/app_topg lib.libavtoraliase.sql', file_get_contents(dirname(__DIR__) . '/tools/app_import_sql.sh'));
	}
}
