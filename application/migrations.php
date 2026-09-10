<?php

function app_migration_files(string $directory): array {
	$files = glob(rtrim($directory, '/') . '/*.sql') ?: [];
	sort($files, SORT_STRING);
	return $files;
}

function app_migrate(PDO $dbh, string $directory): void {
	$dbh->exec('CREATE TABLE IF NOT EXISTS app_migrations (version varchar(255) PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT CURRENT_TIMESTAMP)');
	foreach (app_migration_files($directory) as $file) {
		$version = basename($file);
		$exists = $dbh->prepare('SELECT 1 FROM app_migrations WHERE version = :version');
		$exists->execute([':version' => $version]);
		if ($exists->fetchColumn()) {
			continue;
		}
		$sql = file_get_contents($file);
		if ($sql === false) {
			throw new RuntimeException("Cannot read migration {$file}");
		}
		$dbh->beginTransaction();
		try {
			$dbh->exec($sql);
			$dbh->prepare('INSERT INTO app_migrations (version) VALUES (:version)')->execute([':version' => $version]);
			$dbh->commit();
		} catch (Throwable $error) {
			if ($dbh->inTransaction()) {
				$dbh->rollBack();
			}
			throw $error;
		}
	}
}
