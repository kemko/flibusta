<?php

class FlibustaAuthException extends RuntimeException {}

function flibusta_auth_settings(?array $config = null): array {
	$config = $config ?? flibusta_config();
	$oidc = $config['oidc'];
	$issuer = rtrim((string)$oidc['issuer'], '/');
	$public_url = rtrim((string)$config['public_url'], '/');
	$webroot = '/' . trim((string)$config['webroot'], '/');
	if ($webroot === '/') {
		$webroot = '';
	}
	if (parse_url($issuer, PHP_URL_SCHEME) !== 'https' || parse_url($issuer, PHP_URL_HOST) === null || trim((string)$oidc['client_id']) === '' || parse_url($public_url, PHP_URL_SCHEME) !== 'https' || parse_url($public_url, PHP_URL_HOST) === null) {
		throw new FlibustaAuthException('OIDC requires HTTPS issuer, public URL and client ID');
	}
	return [
		'issuer' => $issuer,
		'client_id' => (string)$oidc['client_id'],
		'client_secret' => (string)$oidc['client_secret'],
		'scopes' => preg_split('/\s+/', trim((string)$oidc['scopes'])) ?: ['openid'],
		'session_seconds' => (int)$oidc['session_seconds'],
		'callback_url' => $public_url . $webroot . '/auth.php',
		'login_url' => $webroot . '/auth.php',
		'home_url' => $webroot . '/',
	];
}

function flibusta_auth_session_start(?array $settings = null): void {
	if (session_status() === PHP_SESSION_ACTIVE) {
		return;
	}
	$settings = $settings ?? flibusta_auth_settings();
	session_name('flibusta_session');
	session_set_cookie_params([
		'lifetime' => $settings['session_seconds'],
		'path' => '/',
		'secure' => true,
		'httponly' => true,
		'samesite' => 'Lax',
	]);
	session_start();
}

function flibusta_auth_is_authenticated(): bool {
	return isset($_SESSION['flibusta_auth_expires']) && is_int($_SESSION['flibusta_auth_expires']) && $_SESSION['flibusta_auth_expires'] >= time();
}

function flibusta_auth_csrf_token(): string {
	if (!isset($_SESSION['flibusta_csrf']) || !is_string($_SESSION['flibusta_csrf'])) {
		$_SESSION['flibusta_csrf'] = bin2hex(random_bytes(32));
	}
	return $_SESSION['flibusta_csrf'];
}

function flibusta_auth_check_csrf(?string $token): bool {
	return is_string($token) && isset($_SESSION['flibusta_csrf']) && is_string($_SESSION['flibusta_csrf']) && hash_equals($_SESSION['flibusta_csrf'], $token);
}

function flibusta_auth_require_post_csrf(): void {
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		http_response_code(405);
		header('Allow: POST');
		exit('POST required');
	}
	if (!flibusta_auth_check_csrf($_POST['csrf'] ?? null)) {
		http_response_code(403);
		exit('Invalid CSRF token');
	}
}

function flibusta_auth_post_form(string $action, array $fields, string $class, string $content): string {
	$inputs = '<input type="hidden" name="csrf" value="' . htmlspecialchars(flibusta_auth_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
	foreach ($fields as $name => $value) {
		$inputs .= '<input type="hidden" name="' . htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . '">';
	}
	return '<form method="post" action="' . htmlspecialchars($action, ENT_QUOTES, 'UTF-8') . '" class="d-inline">' . $inputs . '<button type="submit" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">' . $content . '</button></form>';
}

function flibusta_auth_validate_claims(object $claims, array $settings): void {
	$audience = isset($claims->aud) ? (array)$claims->aud : [];
	if (($claims->iss ?? null) !== $settings['issuer'] || !in_array($settings['client_id'], $audience, true) || !isset($claims->sub) || !is_string($claims->sub) || $claims->sub === '' || !isset($claims->exp) || !is_numeric($claims->exp) || (int)$claims->exp < time()) {
		throw new FlibustaAuthException('OIDC claims are invalid');
	}
}

function flibusta_auth_client(array $settings): \Jumbojett\OpenIDConnectClient {
	if (!class_exists('\Jumbojett\\OpenIDConnectClient')) {
		throw new FlibustaAuthException('OIDC client library is unavailable');
	}
	$client = new \Jumbojett\OpenIDConnectClient($settings['issuer'], $settings['client_id'], $settings['client_secret']);
	$client->setRedirectURL($settings['callback_url']);
	$client->setCodeChallengeMethod('S256');
	$client->addScope($settings['scopes']);
	return $client;
}

function flibusta_auth_complete_login(object $claims, array $settings): void {
	flibusta_auth_validate_claims($claims, $settings);
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_regenerate_id(true);
	}
	$_SESSION = [
		'flibusta_auth_expires' => min((int)$claims->exp, time() + $settings['session_seconds']),
		'flibusta_csrf' => bin2hex(random_bytes(32)),
	];
}

function flibusta_auth_clear(): void {
	$_SESSION = [];
	if (ini_get('session.use_cookies')) {
		$params = session_get_cookie_params();
		setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], (bool)$params['secure'], (bool)$params['httponly']);
	}
	session_destroy();
}

function flibusta_auth_require_web(): void {
	try {
		$settings = flibusta_auth_settings();
		flibusta_auth_session_start($settings);
		if (flibusta_auth_is_authenticated()) {
			return;
		}
		header('Location: ' . $settings['login_url'], true, 302);
	} catch (Throwable $error) {
		http_response_code(503);
		echo 'Authentication is unavailable';
	}
	exit;
}
