<?php

use PHPUnit\Framework\TestCase;

final class CompilationJobsTest extends TestCase {
	private PDO $dbh;
	private string $directory;
	private array $config;

	protected function setUp(): void {
		if (getenv('FLIBUSTA_DBHOST') === false) {
			self::markTestSkipped('PostgreSQL integration test requires the test stack.');
		}
		$this->dbh = new PDO(sprintf('pgsql:host=%s;dbname=%s', getenv('FLIBUSTA_DBHOST'), getenv('FLIBUSTA_DBNAME')), getenv('FLIBUSTA_DBUSER'), getenv('FLIBUSTA_DBPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$this->dbh->exec('DELETE FROM compilation_jobs');
		$this->directory = sys_get_temp_dir() . '/flibusta-cart-' . bin2hex(random_bytes(6));
		mkdir($this->directory . '/books', 0700, true);
		$this->config = flibusta_config();
		$this->config['directories']['books'] = $this->directory . '/books';
		$this->config['directories']['cache'] = $this->directory . '/cache';
		$this->config['directories']['compilations'] = $this->directory . '/results';
		$this->config['limits']['job_seconds'] = 1;
		$this->config['limits']['compilation_retention_seconds'] = 60;
	}

	protected function tearDown(): void {
		$this->dbh->exec('DELETE FROM compilation_jobs');
		$this->removeDirectory($this->directory);
	}

	private function removeDirectory(string $path): void {
		foreach (array_merge(glob($path . '/*') ?: [], glob($path . '/.*') ?: []) as $child) {
			if (in_array(basename($child), ['.', '..'], true)) {
				continue;
			}
			is_dir($child) ? $this->removeDirectory($child) : unlink($child);
		}
		if (is_dir($path)) {
			rmdir($path);
		}
	}

	private function insertJob(string $id, string $owner, string $state = 'queued'): array {
		$data = file_get_contents(__DIR__ . '/fixtures/books/compilation-one.fb2');
		$zip = new ZipArchive();
		self::assertTrue($zip->open($this->directory . '/books/books.zip', ZipArchive::CREATE) === true);
		$zip->addFromString('10.fb2', $data);
		$zip->close();
		$snapshot = [[
			'bookid' => 10, 'title' => 'Первое произведение', 'format' => 'fb2', 'authors' => ['Автор'], 'archive_name' => 'books.zip', 'entry_name' => '10.fb2', 'size_bytes' => strlen($data), 'sha256' => hash('sha256', $data),
		]];
		$this->dbh->prepare("INSERT INTO compilation_jobs (job_id, owner_hash, title, source_snapshot, request_token, state, started_at, expires_at) VALUES (:id, :owner, 'Тестовый сборник', CAST(:snapshot AS jsonb), :token, CAST(:state AS varchar), CASE WHEN CAST(:state AS varchar) = 'processing' THEN CURRENT_TIMESTAMP - INTERVAL '2 seconds' END, CURRENT_TIMESTAMP + INTERVAL '1 hour')")->execute([':id' => $id, ':owner' => $owner, ':snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE), ':token' => bin2hex(random_bytes(24)), ':state' => $state]);
		return $snapshot;
	}

	public function testClaimsPreparesAndPublishesOnlyForOwner(): void {
		$id = '11111111-1111-4111-8111-111111111111';
		$this->insertJob($id, 'owner-one');
		$job = cart_claim_job($this->dbh, 1);
		self::assertSame($id, $job['job_id']);
		cart_prepare_job($job, $this->config);
		$directory = cart_job_directory($this->config['directories']['cache'], $id);
		self::assertFileExists($directory . '/manifest.json');
		exec(PHP_BINARY . ' ' . escapeshellarg(dirname(__DIR__) . '/tools/app_compilation_process.php') . ' ' . escapeshellarg($directory), $output, $status);
		self::assertSame(0, $status);
		self::assertSame(1, cart_collect_jobs($this->dbh, $this->config));
		$job = cart_job_for_owner($this->dbh, $id, 'owner-one');
		self::assertSame('ready', $job['state']);
		self::assertFileExists($job['result_path']);
		self::assertNull(cart_job_for_owner($this->dbh, $id, 'owner-two'));
		$this->dbh->prepare("UPDATE compilation_jobs SET expires_at = CURRENT_TIMESTAMP - INTERVAL '1 second' WHERE job_id = :id")->execute([':id' => $id]);
		self::assertSame(1, cart_cleanup_jobs($this->dbh, $this->config));
		self::assertFileDoesNotExist($job['result_path']);
		self::assertDirectoryDoesNotExist($directory);
	}

	public function testRestartsStaleWorkAndReportsMissingSource(): void {
		$id = '22222222-2222-4222-8222-222222222222';
		$this->insertJob($id, 'owner-one', 'processing');
		$job = cart_claim_job($this->dbh, 1);
		self::assertSame($id, $job['job_id']);
		file_put_contents($this->directory . '/books/books.zip', 'changed');
		$this->expectException(CartException::class);
		cart_prepare_job($job, $this->config);
	}

	public function testCreatesAnImmutableIdempotentSnapshot(): void {
		$data = file_get_contents(__DIR__ . '/fixtures/books/compilation-one.fb2');
		$zip = new ZipArchive();
		self::assertTrue($zip->open($this->directory . '/books/books.zip', ZipArchive::CREATE) === true);
		$zip->addFromString('10.fb2', $data);
		$zip->close();
		$this->dbh->exec('CREATE TEMP TABLE libbook (bookid bigint, title text, filetype text); CREATE TEMP TABLE libfilename (bookid bigint, filename text); CREATE TEMP TABLE book_zip (filename text, usr smallint, start_id bigint, end_id bigint)');
		$this->dbh->exec("INSERT INTO libbook VALUES (10, 'Первое произведение', 'fb2'); INSERT INTO book_zip VALUES ('books.zip', 0, 1, 20)");
		$token = bin2hex(random_bytes(24));
		$first = cart_create_job($this->dbh, 'owner-one', 'Мой сборник', [10], $token, $this->config);
		$second = cart_create_job($this->dbh, 'owner-one', 'Мой сборник', [10], $token, $this->config);
		self::assertSame($first['job_id'], $second['job_id']);
		$snapshot = $this->dbh->prepare('SELECT source_snapshot FROM compilation_jobs WHERE job_id = :id');
		$snapshot->execute([':id' => $first['job_id']]);
		$source = json_decode($snapshot->fetchColumn(), true, 512, JSON_THROW_ON_ERROR)[0];
		self::assertSame(hash('sha256', $data), $source['sha256']);
		self::assertSame('books.zip', $source['archive_name']);
	}
}
