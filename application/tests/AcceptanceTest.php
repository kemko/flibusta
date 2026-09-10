<?php

use PHPUnit\Framework\TestCase;

final class AcceptanceTest extends TestCase {
	private PDO $dbh;
	private string $directory;
	private array $config;

	protected function setUp(): void {
		if (getenv('FLIBUSTA_DBHOST') === false) {
			self::markTestSkipped('PostgreSQL integration test requires the test stack.');
		}
		$this->dbh = new PDO(sprintf('pgsql:host=%s;dbname=%s', getenv('FLIBUSTA_DBHOST'), getenv('FLIBUSTA_DBNAME')), getenv('FLIBUSTA_DBUSER'), getenv('FLIBUSTA_DBPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$this->resetApplicationData();
		$this->directory = sys_get_temp_dir() . '/flibusta-acceptance-' . bin2hex(random_bytes(6));
		mkdir($this->directory . '/books', 0700, true);
		$this->config = flibusta_config();
		$this->config['directories']['books'] = $this->directory . '/books';
		$this->config['directories']['cache'] = $this->directory . '/cache';
		$this->config['directories']['compilations'] = $this->directory . '/results';
		$this->config['limits']['smtp_attachment_bytes'] = 100000;
		$_SESSION = [];
	}

	protected function tearDown(): void {
		$this->resetApplicationData();
		$this->removeDirectory($this->directory);
	}

	private function resetApplicationData(): void {
		$this->dbh->exec('DELETE FROM compilation_mail_requests; DELETE FROM compilation_jobs; DELETE FROM book_extracted_metadata; DELETE FROM book_archive_entries; DELETE FROM book_archives; DELETE FROM author_search_index; DELETE FROM opds_keys');
	}

	private function removeDirectory(string $path): void {
		foreach (glob($path . '/*') ?: [] as $child) {
			if (is_dir($child)) {
				$this->removeDirectory($child);
			} else {
				unlink($child);
			}
		}
		if (is_dir($path)) {
			rmdir($path);
		}
	}

	private function createLibraryTables(): void {
		$this->dbh->exec("CREATE TEMP TABLE libavtorname (avtorid bigint PRIMARY KEY, lastname text NOT NULL DEFAULT '', firstname text NOT NULL DEFAULT '', middlename text NOT NULL DEFAULT '', nickname text NOT NULL DEFAULT '', masterid bigint NOT NULL DEFAULT 0);
			CREATE TEMP TABLE libavtoraliase (badid bigint NOT NULL, goodid bigint NOT NULL);
			CREATE TEMP TABLE libbook (bookid bigint PRIMARY KEY, title text NOT NULL, filetype text NOT NULL, deleted varchar(1) NOT NULL DEFAULT '0');
			CREATE TEMP TABLE libavtor (bookid bigint NOT NULL, avtorid bigint NOT NULL);
			CREATE TEMP TABLE libtranslator (bookid bigint NOT NULL, translatorid bigint NOT NULL, pos smallint NOT NULL DEFAULT 0);
			CREATE TEMP TABLE libfilename (bookid bigint NOT NULL, filename text NOT NULL);
			CREATE TEMP TABLE book_zip (filename text NOT NULL, usr smallint NOT NULL DEFAULT 0, start_id bigint NOT NULL, end_id bigint NOT NULL)");
	}

	private function epub(): string {
		$path = $this->directory . '/book.epub';
		$zip = new ZipArchive();
		self::assertTrue($zip->open($path, ZipArchive::CREATE) === true);
		$zip->addFromString('META-INF/container.xml', '<container><rootfiles><rootfile full-path="content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');
		$zip->addFromString('content.opf', '<package><metadata><creator id="translator">EPUB Translator</creator><meta property="role" refines="#translator">trl</meta></metadata><manifest><item id="cover" href="cover.png" media-type="image/png" properties="cover-image"/></manifest></package>');
		$zip->addFromString('cover.png', "\x89PNG\r\n\x1A\n");
		$zip->close();
		$data = file_get_contents($path);
		unlink($path);
		self::assertIsString($data);
		return $data;
	}

	private function seedLibrary(): void {
		$this->createLibraryTables();
		$this->dbh->exec("INSERT INTO libavtorname (avtorid, lastname, firstname, masterid) VALUES (1, 'Герберт', 'Фрэнк', 0), (2, 'Герберт', 'Френк', 1), (3, 'Переводчик', 'FB2', 0);
			INSERT INTO libavtoraliase VALUES (2, 1);
			INSERT INTO libbook (bookid, title, filetype) VALUES (101, 'Книга FB2', 'fb2'), (102, 'Книга EPUB', 'epub');
			INSERT INTO libavtor VALUES (101, 1), (102, 2);
			INSERT INTO libtranslator VALUES (101, 3, 0);
			INSERT INTO book_zip VALUES ('acceptance.zip', 0, 1, 200)");
		$zip = new ZipArchive();
		self::assertTrue($zip->open($this->directory . '/books/acceptance.zip', ZipArchive::CREATE) === true);
		$zip->addFromString('101.fb2', (string)file_get_contents(__DIR__ . '/fixtures/books/namespaced.fb2'));
		$zip->addFromString('102.epub', $this->epub());
		$zip->close();
		author_search_rebuild($this->dbh);
	}

	private function login(): string {
		$settings = [
			'issuer' => 'https://issuer.example', 'client_id' => 'acceptance-client', 'client_secret' => 'secret', 'session_seconds' => 3600,
			'callback_url' => 'https://library.example/auth.php', 'login_url' => '/auth.php', 'home_url' => '/', 'owner_hmac_key' => 'acceptance-owner',
		];
		flibusta_auth_complete_login((object)['iss' => $settings['issuer'], 'aud' => [$settings['client_id']], 'sub' => 'acceptance-user', 'exp' => time() + 3600], $settings);
		self::assertTrue(flibusta_auth_is_authenticated());
		return (string)flibusta_auth_owner();
	}

	public function testOidcSearchCartCompilationDownloadAndSmtp(): void {
		$this->seedLibrary();
		$owner = $this->login();
		self::assertSame([1], array_map(static fn (object $author): int => (int)$author->author_id, author_search_results($this->dbh, 'Френк Гербертт')));
		self::assertSame([1, 2], author_search_linked_ids($this->dbh, 2)[1]);

		self::assertSame(1, book_index_scan_archives($this->dbh, $this->config['directories']['books']));
		self::assertSame(2, book_index_run_worker($this->dbh, $this->config['directories']['books']));
		$illustrations = $this->dbh->query('SELECT entries.bookid, metadata.illustration_count FROM book_archive_entries entries JOIN book_extracted_metadata metadata USING (entry_id) ORDER BY entries.bookid')->fetchAll(PDO::FETCH_KEY_PAIR);
		self::assertSame(['101' => 2, '102' => 0], array_map('intval', $illustrations));

		cart_add(102, 10);
		cart_add(101, 10);
		cart_move(101, -1);
		self::assertSame([101, 102], cart_items());
		$job = cart_create_job($this->dbh, $owner, 'Приёмочный сборник', cart_items(), cart_request_token(), $this->config);
		$snapshot_statement = $this->dbh->prepare('SELECT source_snapshot FROM compilation_jobs WHERE job_id = :job_id');
		$snapshot_statement->execute([':job_id' => $job['job_id']]);
		$snapshot = json_decode((string)$snapshot_statement->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame([101, 102], array_column($snapshot, 'bookid'));
		self::assertSame(['fb2', 'epub'], array_column($snapshot, 'format'));
		self::assertSame(['EPUB Translator'], $snapshot[1]['extracted_translators']);

		$claimed = cart_claim_job($this->dbh, 1);
		self::assertSame($job['job_id'], $claimed['job_id']);
		cart_prepare_job($claimed, $this->config);
		$compiled = compilation_build('Приёмочный сборник', [
			$snapshot[0] + ['fb2' => (string)file_get_contents(__DIR__ . '/fixtures/books/compilation-one.fb2')],
			$snapshot[1] + ['fb2' => (string)file_get_contents(__DIR__ . '/fixtures/books/compilation-two.fb2')],
		]);
		cart_write_file(cart_job_directory($this->config['directories']['cache'], $job['job_id']) . '/result.fb2', $compiled);
		self::assertSame(1, cart_collect_jobs($this->dbh, $this->config));
		$download = cart_job_for_owner($this->dbh, $job['job_id'], $owner);
		self::assertSame('ready', $download['state']);
		self::assertStringContainsString('Приёмочный сборник', (string)file_get_contents($download['result_path']));
		self::assertNull(cart_job_for_owner($this->dbh, $job['job_id'], 'other-owner'));

		compilation_mail_create_request($this->dbh, $job['job_id'], $owner, bin2hex(random_bytes(24)), $this->config);
		self::assertSame(1, compilation_mail_process_requests($this->dbh, $this->config));
		self::assertSame('accepted', $this->dbh->query('SELECT state FROM compilation_mail_requests')->fetchColumn());
		self::assertCount(1, compilation_mail_requests_for_owner($this->dbh, $owner));
		self::assertSame(0, cart_run_jobs($this->dbh, $this->config));
	}

	public function testOpdsKeySurvivesNoBrowserSessionThenRevocationRefusesIt(): void {
		$owner = $this->login();
		self::assertFalse(flibusta_auth_check_csrf(null));
		self::assertSame($owner, flibusta_auth_owner_hash('https://issuer.example', 'acceptance-user', [
			'owner_hmac_key' => 'acceptance-owner',
		]));
		$key = flibusta_opds_create_key($this->dbh, $owner);
		$_SESSION = [];
		$header = 'Basic ' . base64_encode($key['key_id'] . ':' . $key['secret']);
		$_SERVER['PHP_AUTH_USER'] = $key['key_id'];
		$_SERVER['PHP_AUTH_PW'] = $key['secret'];
		self::assertSame($header, flibusta_opds_basic_header());
		unset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW']);
		self::assertNull(flibusta_opds_authenticate($this->dbh, 'Bearer ignored'));
		foreach (['catalog', 'search', 'download'] as $route) {
			self::assertSame($key['key_id'], flibusta_opds_authenticate($this->dbh, $header), $route);
		}
		self::assertTrue(flibusta_opds_revoke_key($this->dbh, $key['key_id'], $owner));
		self::assertNull(flibusta_opds_authenticate($this->dbh, $header));
		self::assertSame($key['key_id'], flibusta_opds_keys($this->dbh, $owner)[0]['key_id']);
	}

	public function testRepeatedImportRebuildsAliasesWithoutRescanningSuccessfulBooks(): void {
		$this->seedLibrary();
		self::assertSame(1, book_index_scan_archives($this->dbh, $this->config['directories']['books']));
		self::assertSame(2, book_index_run_worker($this->dbh, $this->config['directories']['books']));
		$first = $this->dbh->query('SELECT extracted_at FROM book_extracted_metadata ORDER BY entry_id LIMIT 1')->fetchColumn();
		app_migrate($this->dbh, dirname(__DIR__) . '/tools/migrations');
		author_search_rebuild($this->dbh);
		self::assertSame([1], array_map(static fn (object $author): int => (int)$author->author_id, author_search_results($this->dbh, 'Френк Герберт')));
		self::assertSame(1, book_index_scan_archives($this->dbh, $this->config['directories']['books']));
		self::assertSame(0, book_index_run_worker($this->dbh, $this->config['directories']['books']));
		self::assertSame(0, book_index_reconcile_entries($this->dbh));
		self::assertSame($first, $this->dbh->query('SELECT extracted_at FROM book_extracted_metadata ORDER BY entry_id LIMIT 1')->fetchColumn());
	}
}
