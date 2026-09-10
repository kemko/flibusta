<?php

use PHPUnit\Framework\TestCase;

final class MigrationTest extends TestCase {
	public function testRepeatedMigrationPreservesApplicationData(): void {
		if (getenv('FLIBUSTA_DBHOST') === false) {
			self::markTestSkipped('PostgreSQL integration test requires the test stack.');
		}
		$dsn = sprintf('pgsql:host=%s;dbname=%s', getenv('FLIBUSTA_DBHOST'), getenv('FLIBUSTA_DBNAME'));
		$dbh = new PDO($dsn, getenv('FLIBUSTA_DBUSER'), getenv('FLIBUSTA_DBPASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
		$keyId = 'migration-test-key';
		$dbh->prepare('DELETE FROM opds_keys WHERE key_id = :key_id')->execute([':key_id' => $keyId]);
		app_migrate($dbh, dirname(__DIR__) . '/tools/migrations');
		$dbh->prepare('INSERT INTO opds_keys (key_id, secret_hash, owner_hash) VALUES (:key_id, :secret_hash, :owner_hash)')->execute([
			':key_id' => $keyId,
			':secret_hash' => 'secret-hash',
			':owner_hash' => 'owner-hash',
		]);
		app_migrate($dbh, dirname(__DIR__) . '/tools/migrations');
		self::assertSame('secret-hash', $dbh->query("SELECT secret_hash FROM opds_keys WHERE key_id = 'migration-test-key'")->fetchColumn());
		$dbh->prepare('DELETE FROM opds_keys WHERE key_id = :key_id')->execute([':key_id' => $keyId]);
	}

	public function testMigrationDefinesOnlyApplicationOwnedTables(): void {
		$sql = file_get_contents(dirname(__DIR__) . '/tools/migrations/001_library_extensions.sql');
		self::assertStringContainsString('CREATE TABLE IF NOT EXISTS book_archives', $sql);
		self::assertStringContainsString('CREATE TABLE IF NOT EXISTS opds_keys', $sql);
		self::assertStringNotContainsString('REFERENCES libbook', $sql);
		self::assertStringNotContainsString('REFERENCES libavtor', $sql);
	}

	public function testMigrationFilesAreOrderedAndStable(): void {
		$files = app_migration_files(dirname(__DIR__) . '/tools/migrations');
		self::assertSame(['001_library_extensions.sql'], array_map('basename', $files));
		self::assertSame($files, app_migration_files(dirname(__DIR__) . '/tools/migrations'));
	}
}
