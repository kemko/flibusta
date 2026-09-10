<?php

use PHPUnit\Framework\TestCase;

final class OpdsAccessTest extends TestCase {
	private ?PDO $dbh = null;

	protected function setUp(): void {
		if (getenv('FLIBUSTA_DBHOST') === false) {
			self::markTestSkipped('PostgreSQL integration test requires the test stack.');
		}
		$this->dbh = new PDO(
			sprintf('pgsql:host=%s;dbname=%s', getenv('FLIBUSTA_DBHOST'), getenv('FLIBUSTA_DBNAME')),
			getenv('FLIBUSTA_DBUSER'), getenv('FLIBUSTA_DBPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
		);
	}

	protected function tearDown(): void {
		if ($this->dbh !== null) {
			$this->dbh->exec("DELETE FROM opds_keys WHERE key_id LIKE 'opds_%'");
		}
	}

	public function testKeyCreationBasicAuthenticationRevocationAndOwnerIsolation(): void {
		$first = flibusta_opds_create_key($this->dbh, 'owner-one');
		$second = flibusta_opds_create_key($this->dbh, 'owner-two');
		self::assertMatchesRegularExpression('/^opds_[a-f0-9]{24}$/', $first['key_id']);
		self::assertNotSame($first['secret'], $second['secret']);
		self::assertCount(1, flibusta_opds_keys($this->dbh, 'owner-one'));
		self::assertCount(1, flibusta_opds_keys($this->dbh, 'owner-two'));
		$header = 'Basic ' . base64_encode($first['key_id'] . ':' . $first['secret']);
		self::assertSame($first['key_id'], flibusta_opds_authenticate($this->dbh, $header));
		self::assertNull(flibusta_opds_authenticate($this->dbh, 'Basic ' . base64_encode($first['key_id'] . ':wrong')));
		self::assertFalse(flibusta_opds_revoke_key($this->dbh, $first['key_id'], 'owner-two'));
		self::assertTrue(flibusta_opds_revoke_key($this->dbh, $first['key_id'], 'owner-one'));
		self::assertNull(flibusta_opds_authenticate($this->dbh, $header));
	}

	public function testOpdsAndBookRoutesUseBasicGateOnlyWhereAllowed(): void {
		$index = file_get_contents(dirname(__DIR__) . '/public/index.php');
		self::assertStringContainsString("if (\$url->mod === 'opds')", $index);
		self::assertStringContainsString('flibusta_opds_require($dbh)', $index);
		self::assertStringContainsString('flibusta_auth_require_web()', $index);
		foreach (['fb2.php', 'usr.php', 'extract_cover.php', 'extract_author.php', 'extract_usr.php'] as $file) {
			self::assertStringContainsString('flibusta_auth_require_book_access($dbh)', file_get_contents(dirname(__DIR__) . '/public/' . $file));
		}
		self::assertStringContainsString('flibusta_auth_require_web()', file_get_contents(dirname(__DIR__) . '/public/save_position.php'));
		self::assertStringContainsString('flibusta_auth_require_post_csrf()', file_get_contents(dirname(__DIR__) . '/modules/opds_keys/index.php'));
	}
}
