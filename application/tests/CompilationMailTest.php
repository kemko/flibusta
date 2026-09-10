<?php

use PHPUnit\Framework\TestCase;

final class CompilationMailTest extends TestCase {
	private PDO $dbh;
	private string $directory;
	private array $config;

	protected function setUp(): void {
		if (getenv('FLIBUSTA_DBHOST') === false) {
			self::markTestSkipped('PostgreSQL integration test requires the test stack.');
		}
		$this->dbh = new PDO(sprintf('pgsql:host=%s;dbname=%s', getenv('FLIBUSTA_DBHOST'), getenv('FLIBUSTA_DBNAME')), getenv('FLIBUSTA_DBUSER'), getenv('FLIBUSTA_DBPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$this->dbh->exec('DELETE FROM compilation_mail_requests; DELETE FROM compilation_jobs');
		$this->directory = sys_get_temp_dir() . '/flibusta-mail-' . bin2hex(random_bytes(6));
		mkdir($this->directory, 0700, true);
		$this->config = flibusta_config();
		$this->config['limits']['smtp_attachment_bytes'] = 100000;
	}

	protected function tearDown(): void {
		$this->dbh->exec('DELETE FROM compilation_mail_requests; DELETE FROM compilation_jobs');
		foreach (glob($this->directory . '/*') ?: [] as $file) {
			unlink($file);
		}
		rmdir($this->directory);
	}

	private function readyJob(string $id = '33333333-3333-4333-8333-333333333333'): string {
		$path = $this->directory . '/' . $id . '.fb2';
		file_put_contents($path, '<FictionBook/>');
		$this->dbh->prepare("INSERT INTO compilation_jobs (job_id, owner_hash, title, source_snapshot, request_token, state, result_path, expires_at) VALUES (:id, 'owner-one', 'Письмо', '[]', :token, 'ready', :path, CURRENT_TIMESTAMP + INTERVAL '1 hour')")->execute([':id' => $id, ':token' => bin2hex(random_bytes(24)), ':path' => $path]);
		return $path;
	}

	public function testQueuesOwnerFileOnceAndMarksSmtpAcceptance(): void {
		$path = $this->readyJob();
		$token = bin2hex(random_bytes(24));
		$first = compilation_mail_create_request($this->dbh, '33333333-3333-4333-8333-333333333333', 'owner-one', $token, $this->config);
		$second = compilation_mail_create_request($this->dbh, '33333333-3333-4333-8333-333333333333', 'owner-one', $token, $this->config);
		self::assertSame($first['request_id'], $second['request_id']);
		$sent = [];
		self::assertSame(1, compilation_mail_process_requests($this->dbh, $this->config, static function (array $request) use (&$sent, $path): void {
			$sent[] = $request;
			self::assertSame($path, $request['result_path']);
		}));
		self::assertSame('accepted', $this->dbh->query('SELECT state FROM compilation_mail_requests')->fetchColumn());
		self::assertCount(1, $sent);
		$this->expectException(CompilationMailException::class);
		compilation_mail_create_request($this->dbh, '33333333-3333-4333-8333-333333333333', 'owner-two', bin2hex(random_bytes(24)), $this->config);
	}

	public function testSmtpFailureIsUnknownUntilExplicitRetry(): void {
		$this->readyJob();
		compilation_mail_create_request($this->dbh, '33333333-3333-4333-8333-333333333333', 'owner-one', bin2hex(random_bytes(24)), $this->config);
		compilation_mail_process_requests($this->dbh, $this->config, static function (): void { throw new RuntimeException('connection lost'); });
		self::assertSame('unknown', $this->dbh->query('SELECT state FROM compilation_mail_requests')->fetchColumn());
		self::assertSame(0, compilation_mail_process_requests($this->dbh, $this->config, static function (): void { self::fail('must not retry SMTP automatically'); }));
		$retry = compilation_mail_create_request($this->dbh, '33333333-3333-4333-8333-333333333333', 'owner-one', bin2hex(random_bytes(24)), $this->config);
		self::assertSame('queued', $retry['state']);
	}

	public function testAttachmentLimitAndInternalSmtpSettings(): void {
		$path = $this->readyJob();
		file_put_contents($path, str_repeat('x', 100));
		$this->config['limits']['smtp_attachment_bytes'] = compilation_mail_encoded_size(99);
		$this->expectException(CompilationMailException::class);
		compilation_mail_create_request($this->dbh, '33333333-3333-4333-8333-333333333333', 'owner-one', bin2hex(random_bytes(24)), $this->config);
	}

	public function testLocalSmtpReceiverGetsTheConfiguredAddressAndAttachment(): void {
		$path = $this->readyJob();
		compilation_mail_send(['job_id' => '33333333-3333-4333-8333-333333333333', 'title' => 'Письмо', 'result_path' => $path], $this->config);
		$messages = json_decode((string)file_get_contents('http://smtp-test:8025/api/v1/messages'), true, 512, JSON_THROW_ON_ERROR);
		$list = $messages['messages'] ?? $messages;
		$summary = end($list);
		$id = $summary['ID'] ?? $summary['id'] ?? null;
		self::assertIsString($id);
		$detail = json_decode((string)file_get_contents('http://smtp-test:8025/api/v1/message/' . rawurlencode($id)), true, 512, JSON_THROW_ON_ERROR);
		$serialized = json_encode($detail, JSON_THROW_ON_ERROR);
		self::assertStringContainsString('reader@example.test', $serialized);
		self::assertStringContainsString('compilation-33333333-3333-4333-8333-333333333333.fb2', $serialized);
	}

	public function testSmtpAuthenticationAndTlsSettingsAreValidated(): void {
		$config = $this->config;
		$config['smtp'] = ['host' => 'smtp.example.test', 'port' => 587, 'tls' => 'starttls', 'user' => 'user', 'password' => 'secret', 'from' => 'from@example.test', 'to' => 'to@example.test'];
		self::assertSame('starttls', compilation_mail_settings($config)['tls']);
		$config['smtp']['password'] = '';
		$this->expectException(CompilationMailException::class);
		compilation_mail_settings($config);
	}
}
