<?php

require_once dirname(__DIR__) . '/dbinit.php';
require_once dirname(__DIR__) . '/migrations.php';

if (!isset($dbh) || !$dbh instanceof PDO) {
	fwrite(STDERR, "Database connection is unavailable\n");
	exit(1);
}

app_migrate($dbh, __DIR__ . '/migrations');
echo "Migrations complete\n";
