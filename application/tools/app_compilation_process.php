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
				$command = ['calibre-debug', '-e', '/application/tools/epub_to_fb2.py', '--', $source_path, $output, '--timeout', '900', '--memory-mib', '768', '--work-dir', $directory];
				$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
				if (!is_resource($process)) {
					throw new RuntimeException('Cannot start EPUB converter');
				}
				$output_text = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
				fclose($pipes[1]);
				fclose($pipes[2]);
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
	compilation_process($argv[1]);
}
