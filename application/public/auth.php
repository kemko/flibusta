<?php

require_once '../init.php';

try {
	$settings = flibusta_auth_settings();
	flibusta_auth_session_start($settings);
	if (isset($_GET['logout'])) {
		flibusta_auth_clear();
		header('Location: ' . $settings['home_url'], true, 302);
		exit;
	}
	$client = flibusta_auth_client($settings);
	if (isset($_GET['code']) || isset($_GET['error'])) {
		if (!$client->authenticate()) {
			throw new FlibustaAuthException('OIDC callback was not completed');
		}
		$claims = $client->getVerifiedClaims();
		if (!is_object($claims)) {
			throw new FlibustaAuthException('OIDC did not return verified claims');
		}
		flibusta_auth_complete_login($claims, $settings);
		header('Location: ' . $settings['home_url'], true, 303);
		exit;
	}
	$client->authenticate();
} catch (Throwable $error) {
	http_response_code(503);
	echo 'Authentication is unavailable';
}
