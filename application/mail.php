<?php

class CompilationMailException extends RuntimeException {
}

function compilation_mail_settings(array $config): array {
	$smtp = $config['smtp'] ?? [];
	$host = trim((string)($smtp['host'] ?? ''));
	$from = trim((string)($smtp['from'] ?? ''));
	$to = trim((string)($smtp['to'] ?? ''));
	$tls = strtolower(trim((string)($smtp['tls'] ?? 'starttls')));
	$port = (int)($smtp['port'] ?? 0);
	$user = (string)($smtp['user'] ?? '');
	$password = (string)($smtp['password'] ?? '');
	if ($host === '' || $port < 1 || $port > 65535 || !in_array($tls, ['none', 'starttls', 'smtps'], true) || !filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL) || (($user === '') !== ($password === ''))) {
		throw new CompilationMailException('SMTP configuration is invalid');
	}
	return compact('host', 'port', 'tls', 'user', 'password', 'from', 'to');
}

function compilation_mail_encoded_size(int $bytes): int {
	return intdiv($bytes + 2, 3) * 4 + 8192;
}

function compilation_mail_request_token(string $job_id, bool $retry = false): string {
	if (!preg_match('/^[a-f0-9-]{36}$/', $job_id)) {
		throw new CompilationMailException('Invalid compilation job');
	}
	if ($retry || !isset($_SESSION['flibusta_mail_tokens'][$job_id])) {
		$_SESSION['flibusta_mail_tokens'][$job_id] = bin2hex(random_bytes(24));
	}
	return (string)$_SESSION['flibusta_mail_tokens'][$job_id];
}

function compilation_mail_forget_request_token(string $job_id): void {
	unset($_SESSION['flibusta_mail_tokens'][$job_id]);
}

function compilation_mail_validate_attachment(string $path, array $config): int {
	if (!is_file($path) || !is_readable($path)) {
		throw new CompilationMailException('Compilation is unavailable');
	}
	$size = filesize($path);
	if ($size === false || compilation_mail_encoded_size($size) > (int)$config['limits']['smtp_attachment_bytes']) {
		throw new CompilationMailException('Compilation exceeds the SMTP attachment limit');
	}
	return $size;
}

function compilation_mail_create_request(PDO $dbh, string $job_id, string $owner_hash, string $request_token, array $config): array {
	if (!preg_match('/^[a-f0-9-]{36}$/', $job_id) || !preg_match('/^[a-f0-9]{32,64}$/', $request_token)) {
		throw new CompilationMailException('Invalid mail request');
	}
	$job = cart_job_for_owner($dbh, $job_id, $owner_hash);
	if ($job === null || $job['state'] !== 'ready' || !is_string($job['result_path'])) {
		throw new CompilationMailException('Compilation is unavailable');
	}
	compilation_mail_settings($config);
	compilation_mail_validate_attachment($job['result_path'], $config);
	$existing = $dbh->prepare('SELECT request_id, state FROM compilation_mail_requests WHERE owner_hash = :owner AND request_token = :token');
	$existing->execute([':owner' => $owner_hash, ':token' => $request_token]);
	if ($request = $existing->fetch(PDO::FETCH_ASSOC)) {
		return $request;
	}
	$request_id = sprintf('%s-%s-%s-%s-%s', bin2hex(random_bytes(4)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(2)), bin2hex(random_bytes(6)));
	try {
		$dbh->prepare("INSERT INTO compilation_mail_requests (request_id, job_id, owner_hash, request_token) VALUES (:request, :job, :owner, :token)")->execute([':request' => $request_id, ':job' => $job_id, ':owner' => $owner_hash, ':token' => $request_token]);
	} catch (PDOException $error) {
		$existing->execute([':owner' => $owner_hash, ':token' => $request_token]);
		if ($request = $existing->fetch(PDO::FETCH_ASSOC)) {
			return $request;
		}
		throw $error;
	}
	return ['request_id' => $request_id, 'state' => 'queued'];
}

function compilation_mail_claim_request(PDO $dbh, int $stale_seconds): ?array {
	$dbh->beginTransaction();
	try {
		$dbh->prepare("UPDATE compilation_mail_requests SET state = 'unknown', error = 'SMTP result is unknown after worker interruption', completed_at = CURRENT_TIMESTAMP WHERE state = 'processing' AND started_at < CURRENT_TIMESTAMP - (:stale || ' seconds')::interval")->execute([':stale' => $stale_seconds]);
		$request = $dbh->query("SELECT r.request_id, r.job_id, j.title, j.result_path FROM compilation_mail_requests r JOIN compilation_jobs j USING (job_id) WHERE r.state = 'queued' AND j.state = 'ready' AND j.expires_at > CURRENT_TIMESTAMP ORDER BY r.created_at FOR UPDATE OF r SKIP LOCKED LIMIT 1")->fetch(PDO::FETCH_ASSOC);
		if ($request === false) {
			$dbh->commit();
			return null;
		}
		$dbh->prepare("UPDATE compilation_mail_requests SET state = 'processing', started_at = CURRENT_TIMESTAMP, error = NULL WHERE request_id = :request")->execute([':request' => $request['request_id']]);
		$dbh->commit();
		return $request;
	} catch (Throwable $error) {
		if ($dbh->inTransaction()) {
			$dbh->rollBack();
		}
		throw $error;
	}
}

function compilation_mail_send(array $request, array $config): void {
	$settings = compilation_mail_settings($config);
	compilation_mail_validate_attachment((string)$request['result_path'], $config);
	if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
		throw new CompilationMailException('PHPMailer is unavailable');
	}
	$mail = new \PHPMailer\PHPMailer\PHPMailer(true);
	$mail->isSMTP();
	$mail->Host = $settings['host'];
	$mail->Port = $settings['port'];
	$mail->SMTPAuth = $settings['user'] !== '';
	$mail->Username = $settings['user'];
	$mail->Password = $settings['password'];
	if ($settings['tls'] === 'starttls') {
		$mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
	} elseif ($settings['tls'] === 'smtps') {
		$mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
	}
	$mail->setFrom($settings['from']);
	$mail->addAddress($settings['to']);
	$mail->Subject = 'Flibusta: ' . (string)$request['title'];
	$mail->Body = 'Сборник FB2 приложен к этому письму.';
	$mail->addAttachment((string)$request['result_path'], 'compilation-' . (string)$request['job_id'] . '.fb2', \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64, 'application/x-fictionbook+xml');
	$mail->send();
}

function compilation_mail_process_requests(PDO $dbh, array $config, ?callable $sender = null): int {
	$request = compilation_mail_claim_request($dbh, (int)$config['limits']['job_seconds']);
	if ($request === null) {
		return 0;
	}
	try {
		($sender ?? 'compilation_mail_send')($request, $config);
		$dbh->prepare("UPDATE compilation_mail_requests SET state = 'accepted', accepted_at = CURRENT_TIMESTAMP, completed_at = CURRENT_TIMESTAMP WHERE request_id = :request AND state = 'processing'")->execute([':request' => $request['request_id']]);
	} catch (Throwable $error) {
		$dbh->prepare("UPDATE compilation_mail_requests SET state = 'unknown', error = :error, completed_at = CURRENT_TIMESTAMP WHERE request_id = :request AND state = 'processing'")->execute([':error' => substr($error->getMessage(), 0, 4096), ':request' => $request['request_id']]);
	}
	return 1;
}

function compilation_mail_requests_for_owner(PDO $dbh, string $owner_hash): array {
	$statement = $dbh->prepare('SELECT request_id, job_id, state, error, created_at FROM compilation_mail_requests WHERE owner_hash = :owner ORDER BY created_at DESC');
	$statement->execute([':owner' => $owner_hash]);
	return $statement->fetchAll(PDO::FETCH_ASSOC);
}
