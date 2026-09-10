<?php

if ($argc !== 2 || !is_file($argv[1])) {
	throw new RuntimeException('PHP coverage report is required');
}

$report = simplexml_load_file($argv[1]);
if ($report === false) {
	throw new RuntimeException('Cannot read PHP coverage report');
}

$statements = 0;
$covered = 0;
$files = [];
foreach ($report->xpath('//file') as $file) {
	$metrics = $file->metrics;
	$file_statements = (int)$metrics['statements'];
	$file_covered = (int)$metrics['coveredstatements'];
	$statements += $file_statements;
	$covered += $file_covered;
	$files[] = basename((string)$file['name']) . '=' . ($file_statements ? sprintf('%.0f%%', 100 * $file_covered / $file_statements) : 'n/a');
}
if ($statements === 0 || $covered / $statements < 0.8) {
	throw new RuntimeException(sprintf('PHP coverage is %.2f%%, expected at least 80%% (%s)', $statements ? 100 * $covered / $statements : 0, implode(', ', $files)));
}
echo sprintf("PHP coverage: %.2f%%\n", 100 * $covered / $statements);
