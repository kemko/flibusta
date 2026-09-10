<?php

require_once dirname(__DIR__) . '/book_metadata.php';
require_once dirname(__DIR__) . '/compilation.php';

function compilation_process(string $directory): void {
	$directory = realpath($directory) ?: '';
	if ($directory === '' || !is_file($directory . '/manifest.json') || is_file($directory . '/result.fb2') || is_file($directory . '/error.txt')) {
		return;
	}
	$lock = fopen($directory . '/.lock', 'c');
	if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
		return;
	}
	try {
		$manifest = json_decode((string)file_get_contents($directory . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
		if (!is_array($manifest) || !is_string($manifest['title'] ?? null) || !is_array($manifest['sources'] ?? null)) {
			throw new RuntimeException('Compilation manifest is invalid');
		}
		$books = [];
		foreach ($manifest['sources'] as $index => $source) {
			if (!is_array($source) || !is_string($source['file'] ?? null) || !preg_match('/^source-[0-9]+\.(fb2|epub)$/', $source['file'])) {
				throw new RuntimeException('Compilation source is invalid');
			}
			$source_path = $directory . '/' . $source['file'];
			if (!is_file($source_path)) {
				throw new RuntimeException('Compilation source disappeared');
			}
			if (strtolower((string)$source['format']) === 'epub') {
				$output = $directory . '/converted-' . $index . '.fb2';
				$command = ['calibre-debug', '-e', '/application/tools/epub_to_fb2.py', '--', $source_path, $output, '--xsd', dirname(__DIR__) . '/schema/FictionBook.xsd', '--timeout', (string)max(1, (int)($manifest['job_seconds'] ?? 900)), '--memory-mib', '768', '--work-dir', $directory];
				$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
				if (!is_resource($process)) {
					throw new RuntimeException('Cannot start EPUB converter');
				}
				$output_text = stream_get_contents($pipes[1]);
				fclose($pipes[1]);
				if (proc_close($process) !== 0 || !is_file($output)) {
					throw new RuntimeException('EPUB conversion failed: ' . trim($output_text));
				}
				$source_path = $output;
			}
			$source['fb2'] = file_get_contents($source_path);
			if (!is_string($source['fb2'])) {
				throw new RuntimeException('Cannot read converted FB2');
			}
			$books[] = $source;
		}
		$result = compilation_build($manifest['title'], $books);
		$tmp = tempnam($directory, '.result-');
		if ($tmp === false || file_put_contents($tmp, $result, LOCK_EX) === false || !rename($tmp, $directory . '/result.fb2')) {
			if ($tmp !== false && is_file($tmp)) {
				unlink($tmp);
			}
			throw new RuntimeException('Cannot publish compilation result');
		}
	} catch (Throwable $error) {
		file_put_contents($directory . '/error.txt', $error->getMessage(), LOCK_EX);
	} finally {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
}

if (PHP_SAPI === 'cli' && isset($argv[1])) {
	if (($argv[2] ?? '') === '--run') {
		compilation_process($argv[1]);
	} elseif (is_file($argv[1] . '/manifest.json') && !is_file($argv[1] . '/result.fb2') && !is_file($argv[1] . '/error.txt')) {
		$manifest = json_decode((string)file_get_contents($argv[1] . '/manifest.json'), true);
		$seconds = max(1, (int)($manifest['job_seconds'] ?? 900));
		// GNU timeout kills the entire process group, including Calibre children.
		$process = proc_open(['timeout', '--signal=KILL', (string)$seconds, PHP_BINARY, __FILE__, $argv[1], '--run'], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
		if (is_resource($process)) {
			$output = stream_get_contents($pipes[1]);
			fclose($pipes[1]);
			$process_status = proc_get_status($process);
			$status = proc_close($process);
			if (!$process_status['running']) {
				$status = $process_status['signaled'] ? 128 + $process_status['termsig'] : $process_status['exitcode'];
			}
		} else {
			$status = -1;
			$output = 'Cannot start compilation process';
		}
		if ($status !== 0 && !is_file($argv[1] . '/result.fb2')) {
			file_put_contents($argv[1] . '/error.txt', in_array($status, [124, 137], true) ? 'Compilation timed out' : 'Compilation process failed: ' . substr(trim($output), 0, 4096), LOCK_EX);
		}
	}
}
