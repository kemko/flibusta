<?php

class CartException extends RuntimeException {
}

function cart_items(): array {
	$items = $_SESSION['flibusta_cart'] ?? [];
	if (!is_array($items)) {
		$items = [];
	}
	$items = array_values(array_unique(array_filter(array_map('intval', $items), static fn (int $id): bool => $id > 0)));
	$_SESSION['flibusta_cart'] = $items;
	return $items;
}

function cart_count(): int {
	return count(cart_items());
}

function cart_add(int $bookid, int $maximum): bool {
	$items = cart_items();
	if (in_array($bookid, $items, true)) {
		return false;
	}
	if ($bookid < 1 || count($items) >= $maximum) {
		throw new CartException('Cart book limit exceeded');
	}
	$items[] = $bookid;
	$_SESSION['flibusta_cart'] = $items;
	return true;
}

function cart_remove(int $bookid): void {
	$_SESSION['flibusta_cart'] = array_values(array_filter(cart_items(), static fn (int $id): bool => $id !== $bookid));
}

function cart_move(int $bookid, int $direction): void {
	$items = cart_items();
	$position = array_search($bookid, $items, true);
	$target = $position === false ? -1 : $position + ($direction < 0 ? -1 : 1);
	if ($position !== false && $target >= 0 && $target < count($items)) {
		[$items[$position], $items[$target]] = [$items[$target], $items[$position]];
		$_SESSION['flibusta_cart'] = $items;
	}
}

function cart_request_token(): string {
	if (!isset($_SESSION['flibusta_cart_request']) || !is_string($_SESSION['flibusta_cart_request'])) {
		$_SESSION['flibusta_cart_request'] = bin2hex(random_bytes(24));
	}
	return $_SESSION['flibusta_cart_request'];
}

function cart_text_length(string $value): int {
	return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function cart_book_people(PDO $dbh, int $bookid, string $kind): array {
	try {
		if ($kind === 'author') {
			$statement = $dbh->prepare("SELECT trim(concat_ws(' ', lastname, firstname, middlename, nickname)) FROM libavtor JOIN libavtorname USING (avtorid) WHERE bookid = :bookid ORDER BY avtorid");
		} else {
			$statement = $dbh->prepare("SELECT trim(concat_ws(' ', lastname, firstname, middlename, nickname)) FROM libtranslator JOIN libavtorname ON libtranslator.translatorid = libavtorname.avtorid WHERE bookid = :bookid ORDER BY pos");
		}
		$statement->execute([':bookid' => $bookid]);
		return array_values(array_filter($statement->fetchAll(PDO::FETCH_COLUMN)));
	} catch (PDOException $error) {
		return [];
	}
}

function cart_snapshot_book(PDO $dbh, int $bookid, array $config): array {
	$statement = $dbh->prepare('SELECT bookid, title, filetype FROM libbook WHERE bookid = :bookid');
	$statement->execute([':bookid' => $bookid]);
	$book = $statement->fetch(PDO::FETCH_ASSOC);
	if ($book === false || !in_array(strtolower(trim((string)$book['filetype'])), ['fb2', 'epub'], true)) {
		throw new CartException("Book {$bookid} is unavailable for a compilation");
	}
	try {
		$file = book_file_find($dbh, $bookid, $config['directories']['books'], $config['limits']);
		try {
			$data = book_file_contents($file, $config['limits']);
		} catch (BookFileException $error) {
			throw new CartException('Compilation source changed or disappeared', 0, $error);
		}
	} catch (BookFileException $error) {
		throw new CartException("Book {$bookid} source is unavailable", 0, $error);
	}
	$primary_translators = cart_book_people($dbh, $bookid, 'translator');
	$extracted_translators = $primary_translators === [] ? book_metadata_extract(trim((string)$book['filetype']), $data, $config['limits'])['translators'] : [];
	return [
		'bookid' => $bookid,
		'title' => (string)$book['title'],
		'format' => strtolower(trim((string)$book['filetype'])),
		'authors' => cart_book_people($dbh, $bookid, 'author'),
		'primary_translators' => $primary_translators,
		'extracted_translators' => $extracted_translators,
		'url' => rtrim($config['public_url'], '/') . '/' . trim($config['webroot'], '/') . (trim($config['webroot'], '/') === '' ? '' : '/') . 'book/view/' . $bookid,
		'archive_name' => (string)$file['archive_name'],
		'entry_name' => (string)$file['entry_name'],
		'size_bytes' => strlen($data),
		'sha256' => hash('sha256', $data),
	];
}

function cart_create_job(PDO $dbh, string $owner_hash, string $title, array $bookids, string $request_token, array $config): array {
	$title = trim($title);
	if ($owner_hash === '' || $title === '' || cart_text_length($title) > 256 || !preg_match('/^[a-f0-9]{32,64}$/', $request_token)) {
		throw new CartException('Invalid compilation request');
	}
	$bookids = array_values(array_unique(array_filter(array_map('intval', $bookids), static fn (int $id): bool => $id > 0)));
	if ($bookids === [] || count($bookids) > $config['limits']['compilation_books']) {
		throw new CartException('Compilation book limit exceeded');
	}
	$existing = $dbh->prepare('SELECT job_id, state FROM compilation_jobs WHERE owner_hash = :owner_hash AND request_token = :token');
	$existing->execute([':owner_hash' => $owner_hash, ':token' => $request_token]);
	if ($job = $existing->fetch(PDO::FETCH_ASSOC)) {
		return $job;
	}
	$sources = [];
	$total = 0;
	foreach ($bookids as $bookid) {
		$source = cart_snapshot_book($dbh, $bookid, $config);
		$total += $source['size_bytes'];
		if ($total > $config['limits']['compilation_bytes']) {
			throw new CartException('Compilation size limit exceeded');
		}
		$sources[] = $source;
	}
	$job_id = sprintf('%s-%s-%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(6)));
	$statement = $dbh->prepare("INSERT INTO compilation_jobs (job_id, owner_hash, title, source_snapshot, request_token, state, expires_at) VALUES (:job_id, :owner_hash, :title, CAST(:snapshot AS jsonb), :token, 'queued', CURRENT_TIMESTAMP + (:retention || ' seconds')::interval)");
	try {
		$statement->execute([':job_id' => $job_id, ':owner_hash' => $owner_hash, ':title' => $title, ':snapshot' => json_encode($sources, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ':token' => $request_token, ':retention' => $config['limits']['compilation_retention_seconds']]);
	} catch (PDOException $error) {
		$existing->execute([':owner_hash' => $owner_hash, ':token' => $request_token]);
		if ($job = $existing->fetch(PDO::FETCH_ASSOC)) {
			return $job;
		}
		throw $error;
	}
	unset($_SESSION['flibusta_cart_request']);
	return ['job_id' => $job_id, 'state' => 'queued'];
}

function cart_job_directory(string $cache_directory, string $job_id): string {
	if (!preg_match('/^[a-f0-9-]{36}$/', $job_id)) {
		throw new CartException('Invalid compilation job');
	}
	return rtrim($cache_directory, '/') . '/compilations/work/' . $job_id;
}

function cart_write_file(string $path, string $contents): void {
	$directory = dirname($path);
	if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
		throw new CartException('Cannot create compilation directory');
	}
	$tmp = tempnam($directory, '.pending-');
	if ($tmp === false || file_put_contents($tmp, $contents, LOCK_EX) === false || !rename($tmp, $path)) {
		if ($tmp !== false && is_file($tmp)) {
			unlink($tmp);
		}
		throw new CartException('Cannot write compilation file');
	}
}

function cart_claim_job(PDO $dbh, int $stale_seconds): ?array {
	$dbh->beginTransaction();
	try {
		$dbh->prepare("UPDATE compilation_jobs SET state = 'queued', started_at = NULL WHERE state = 'processing' AND started_at < CURRENT_TIMESTAMP - (:stale || ' seconds')::interval")->execute([':stale' => $stale_seconds]);
		$select = $dbh->query("SELECT job_id, title, source_snapshot FROM compilation_jobs WHERE state = 'queued' AND expires_at > CURRENT_TIMESTAMP ORDER BY created_at FOR UPDATE SKIP LOCKED LIMIT 1");
		$job = $select->fetch(PDO::FETCH_ASSOC);
		if ($job === false) {
			$dbh->commit();
			return null;
		}
		$dbh->prepare("UPDATE compilation_jobs SET state = 'processing', started_at = CURRENT_TIMESTAMP, error = NULL WHERE job_id = :job_id")->execute([':job_id' => $job['job_id']]);
		$dbh->commit();
		return $job;
	} catch (Throwable $error) {
		if ($dbh->inTransaction()) {
			$dbh->rollBack();
		}
		throw $error;
	}
}

function cart_prepare_job(array $job, array $config): void {
	$sources = json_decode((string)$job['source_snapshot'], true, 512, JSON_THROW_ON_ERROR);
	if (!is_array($sources) || $sources === []) {
		throw new CartException('Compilation snapshot is invalid');
	}
	$directory = cart_job_directory($config['directories']['cache'], (string)$job['job_id']);
	if (is_file($directory . '/manifest.json')) {
		return;
	}
	$manifest = ['title' => $job['title'], 'job_seconds' => max(1, (int)$config['limits']['job_seconds']), 'sources' => []];
	foreach ($sources as $index => $source) {
		if (!is_array($source) || !isset($source['archive_name'], $source['entry_name'], $source['sha256'], $source['format'])) {
			throw new CartException('Compilation snapshot source is invalid');
		}
		$file = ['archive_path' => rtrim($config['directories']['books'], '/') . '/' . $source['archive_name'], 'archive_name' => $source['archive_name'], 'entry_name' => $source['entry_name']];
		try {
			$data = book_file_contents($file, $config['limits']);
		} catch (BookFileException $error) {
			throw new CartException('Compilation source changed or disappeared', 0, $error);
		}
		if (!hash_equals((string)$source['sha256'], hash('sha256', $data))) {
			throw new CartException('Compilation source changed or disappeared');
		}
		$source_file = 'source-' . $index . '.' . strtolower((string)$source['format']);
		cart_write_file($directory . '/' . $source_file, $data);
		$source['file'] = $source_file;
		unset($source['archive_name'], $source['entry_name'], $source['sha256']);
		$manifest['sources'][] = $source;
	}
	cart_write_file($directory . '/manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
}

function cart_collect_jobs(PDO $dbh, array $config): int {
	$ready = 0;
	$statement = $dbh->query("SELECT job_id FROM compilation_jobs WHERE state = 'processing'");
	foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $job_id) {
		$directory = cart_job_directory($config['directories']['cache'], (string)$job_id);
		$result = $directory . '/result.fb2';
		$error = $directory . '/error.txt';
		if (is_file($result)) {
			$final_directory = rtrim($config['directories']['compilations'], '/');
			if (!is_dir($final_directory) && !mkdir($final_directory, 0700, true) && !is_dir($final_directory)) {
				throw new CartException('Cannot create compilation results directory');
			}
			$final = $final_directory . '/' . $job_id . '.fb2';
			if (!rename($result, $final)) {
				throw new CartException('Cannot publish compilation result');
			}
			$dbh->prepare("UPDATE compilation_jobs SET state = 'ready', result_path = :result, completed_at = CURRENT_TIMESTAMP WHERE job_id = :job_id AND state = 'processing'")->execute([':result' => $final, ':job_id' => $job_id]);
			$ready++;
		} elseif (is_file($error)) {
			$dbh->prepare("UPDATE compilation_jobs SET state = 'error', error = :error, completed_at = CURRENT_TIMESTAMP WHERE job_id = :job_id AND state = 'processing'")->execute([':error' => substr((string)file_get_contents($error), 0, 4096), ':job_id' => $job_id]);
		}
	}
	return $ready;
}

function cart_cleanup_jobs(PDO $dbh, array $config): int {
	$expired = $dbh->query('SELECT job_id, result_path FROM compilation_jobs WHERE expires_at <= CURRENT_TIMESTAMP')->fetchAll(PDO::FETCH_ASSOC);
	foreach ($expired as $job) {
		$path = (string)($job['result_path'] ?? '');
		$base = rtrim($config['directories']['compilations'], '/') . '/';
		if ($path !== '' && str_starts_with($path, $base) && is_file($path)) {
			unlink($path);
		}
		$work = cart_job_directory($config['directories']['cache'], (string)$job['job_id']);
		if (is_dir($work)) {
			$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
			foreach ($files as $file) {
				$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
			}
			rmdir($work);
		}
	}
	if ($expired !== []) {
		$ids = array_column($expired, 'job_id');
		$placeholders = implode(', ', array_fill(0, count($ids), '?'));
		$dbh->prepare("DELETE FROM compilation_jobs WHERE job_id IN ({$placeholders})")->execute($ids);
	}
	return count($expired);
}

function cart_run_jobs(PDO $dbh, array $config): int {
	cart_cleanup_jobs($dbh, $config);
	$completed = cart_collect_jobs($dbh, $config);
	if ($job = cart_claim_job($dbh, $config['limits']['job_seconds'])) {
		try {
			cart_prepare_job($job, $config);
		} catch (Throwable $error) {
			$dbh->prepare("UPDATE compilation_jobs SET state = 'error', error = :error, completed_at = CURRENT_TIMESTAMP WHERE job_id = :job_id")->execute([':error' => $error->getMessage(), ':job_id' => $job['job_id']]);
		}
	}
	return $completed;
}

function cart_job_for_owner(PDO $dbh, string $job_id, string $owner_hash): ?array {
	$statement = $dbh->prepare("SELECT job_id, title, state, error, result_path, created_at, completed_at FROM compilation_jobs WHERE job_id = :job_id AND owner_hash = :owner_hash");
	$statement->execute([':job_id' => $job_id, ':owner_hash' => $owner_hash]);
	return $statement->fetch(PDO::FETCH_ASSOC) ?: null;
}
