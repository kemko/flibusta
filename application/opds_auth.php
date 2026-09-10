<?php

function flibusta_opds_create_key(PDO $dbh, string $owner_hash): array {
	if ($owner_hash === '') {
		throw new RuntimeException('Authenticated owner is required');
	}
	for ($attempt = 0; $attempt < 3; $attempt++) {
		$key_id = 'opds_' . bin2hex(random_bytes(12));
		$secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
		$insert = $dbh->prepare('INSERT INTO opds_keys (key_id, secret_hash, owner_hash) VALUES (:key_id, :secret_hash, :owner_hash)');
		try {
			$insert->execute([
				':key_id' => $key_id,
				':secret_hash' => password_hash($secret, PASSWORD_DEFAULT),
				':owner_hash' => $owner_hash,
			]);
			return ['key_id' => $key_id, 'secret' => $secret];
		} catch (PDOException $error) {
			if ($error->getCode() !== '23505') {
				throw $error;
			}
		}
	}
	throw new RuntimeException('Cannot create OPDS key');
}

function flibusta_opds_keys(PDO $dbh, string $owner_hash): array {
	$query = $dbh->prepare('SELECT key_id, created_at, revoked_at, last_used_at FROM opds_keys WHERE owner_hash = :owner_hash ORDER BY created_at DESC');
	$query->execute([':owner_hash' => $owner_hash]);
	return $query->fetchAll(PDO::FETCH_ASSOC);
}

function flibusta_opds_revoke_key(PDO $dbh, string $key_id, string $owner_hash): bool {
	$query = $dbh->prepare('UPDATE opds_keys SET revoked_at = CURRENT_TIMESTAMP WHERE key_id = :key_id AND owner_hash = :owner_hash AND revoked_at IS NULL');
	$query->execute([':key_id' => $key_id, ':owner_hash' => $owner_hash]);
	return $query->rowCount() === 1;
}

function flibusta_opds_basic_header(): ?string {
	if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
		return 'Basic ' . base64_encode($_SERVER['PHP_AUTH_USER'] . ':' . $_SERVER['PHP_AUTH_PW']);
	}
	return $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
}

function flibusta_opds_authenticate(PDO $dbh, ?string $header = null): ?string {
	$header = $header ?? flibusta_opds_basic_header();
	if (!is_string($header) || !preg_match('/^Basic\s+(.+)$/i', $header, $match)) {
		return null;
	}
	$decoded = base64_decode($match[1], true);
	if ($decoded === false || !str_contains($decoded, ':')) {
		return null;
	}
	[$key_id, $secret] = explode(':', $decoded, 2);
	if (!preg_match('/^opds_[a-f0-9]{24}$/', $key_id) || $secret === '') {
		return null;
	}
	$query = $dbh->prepare('SELECT secret_hash FROM opds_keys WHERE key_id = :key_id AND revoked_at IS NULL');
	$query->execute([':key_id' => $key_id]);
	$row = $query->fetch(PDO::FETCH_ASSOC);
	if ($row === false || !password_verify($secret, $row['secret_hash'])) {
		return null;
	}
	$dbh->prepare('UPDATE opds_keys SET last_used_at = CURRENT_TIMESTAMP WHERE key_id = :key_id')->execute([':key_id' => $key_id]);
	return $key_id;
}

function flibusta_opds_require(PDO $dbh): void {
	if (flibusta_opds_authenticate($dbh) !== null) {
		return;
	}
	header('WWW-Authenticate: Basic realm="Flibusta OPDS", charset="UTF-8"');
	http_response_code(401);
	exit('OPDS key required');
}
