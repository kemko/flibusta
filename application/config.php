<?php

function flibusta_config_value(string $name, $default = null) {
	$file = getenv($name . '_FILE');
	if ($file !== false && $file !== '') {
		if (!is_readable($file)) {
			throw new RuntimeException("Secret file for {$name} is not readable");
		}
		$value = file_get_contents($file);
		if ($value === false) {
			throw new RuntimeException("Cannot read secret file for {$name}");
		}
		return trim($value);
	}
	$value = getenv($name);
	return $value === false || $value === '' ? $default : $value;
}

function flibusta_config_int(string $name, int $default): int {
	$value = flibusta_config_value($name, (string)$default);
	if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < 0) {
		throw new RuntimeException("{$name} must be a non-negative integer");
	}
	return (int)$value;
}

function flibusta_config(): array {
	return [
		'public_url' => rtrim(flibusta_config_value('FLIBUSTA_PUBLIC_URL', 'https://localhost'), '/'),
		'webroot' => flibusta_config_value('FLIBUSTA_WEBROOT', ''),
		'directories' => [
			'books' => flibusta_config_value('FLIBUSTA_BOOKS_DIR', '/application/flibusta'),
			'cache' => flibusta_config_value('FLIBUSTA_CACHE_DIR', '/application/cache'),
			'tmp' => flibusta_config_value('FLIBUSTA_TMP_DIR', '/application/cache/tmp'),
			'compilations' => flibusta_config_value('FLIBUSTA_COMPILATIONS_DIR', '/application/cache/compilations'),
		],
		'limits' => [
			'archive_bytes' => flibusta_config_int('FLIBUSTA_MAX_ARCHIVE_BYTES', 1073741824),
			'entry_bytes' => flibusta_config_int('FLIBUSTA_MAX_ENTRY_BYTES', 104857600),
			'compilation_books' => flibusta_config_int('FLIBUSTA_MAX_COMPILATION_BOOKS', 100),
			'compilation_bytes' => flibusta_config_int('FLIBUSTA_MAX_COMPILATION_BYTES', 524288000),
			'job_seconds' => flibusta_config_int('FLIBUSTA_JOB_TIMEOUT_SECONDS', 900),
			'compilation_retention_seconds' => flibusta_config_int('FLIBUSTA_COMPILATION_RETENTION_SECONDS', 86400),
		],
		'oidc' => [
			'issuer' => flibusta_config_value('FLIBUSTA_OIDC_ISSUER'),
			'client_id' => flibusta_config_value('FLIBUSTA_OIDC_CLIENT_ID'),
			'client_secret' => flibusta_config_value('FLIBUSTA_OIDC_CLIENT_SECRET'),
			'scopes' => flibusta_config_value('FLIBUSTA_OIDC_SCOPES', 'openid'),
			'session_seconds' => flibusta_config_int('FLIBUSTA_OIDC_SESSION_SECONDS', 28800),
		],
		'opds' => [
			'owner_hmac_key' => flibusta_config_value('FLIBUSTA_OPDS_OWNER_HMAC_KEY'),
		],
		'smtp' => [
			'host' => flibusta_config_value('FLIBUSTA_SMTP_HOST'),
			'port' => flibusta_config_int('FLIBUSTA_SMTP_PORT', 587),
			'tls' => flibusta_config_value('FLIBUSTA_SMTP_TLS', 'starttls'),
			'user' => flibusta_config_value('FLIBUSTA_SMTP_USER'),
			'password' => flibusta_config_value('FLIBUSTA_SMTP_PASSWORD'),
			'from' => flibusta_config_value('FLIBUSTA_SMTP_FROM'),
		],
	];
}
