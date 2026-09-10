<?php

use PHPUnit\Framework\TestCase;

final class ConfigurationExamplesTest extends TestCase {
	private function root(): string {
		return is_dir('/project') ? '/project' : dirname(__DIR__, 2);
	}

	private function contents(string $path): string {
		if (!is_file($path) && str_starts_with($path, '/project/application/')) {
			$path = '/application/' . substr($path, strlen('/project/application/'));
		}
		$contents = file_get_contents($path);
		self::assertIsString($contents, $path);
		return $contents;
	}

	public function testMainAndExternalComposeExposeRequiredServicesAndSecrets(): void {
		$main = $this->contents($this->root() . '/docker-compose.yml');
		$external = $this->contents($this->root() . '/application/tools/external_services_config/docker-compose.yml');
		foreach (['book-worker:', 'compilation-converter:', 'FLIBUSTA_DBPASSWORD_FILE', 'FLIBUSTA_OIDC_CLIENT_SECRET_FILE', 'FLIBUSTA_OPDS_OWNER_HMAC_KEY_FILE', 'FLIBUSTA_SMTP_PASSWORD_FILE'] as $required) {
			self::assertStringContainsString($required, $main);
		}
		foreach (['book-worker:', 'compilation-converter:', 'FLIBUSTA_OIDC_CLIENT_SECRET_FILE', 'FLIBUSTA_OPDS_OWNER_HMAC_KEY_FILE', 'FLIBUSTA_SMTP_PASSWORD_FILE'] as $required) {
			self::assertStringContainsString($required, $external);
		}
	}

	public function testExternalNginxForwardsAuthorizationWithoutAnIndependentBasicGate(): void {
		$nginx = $this->contents($this->root() . '/application/tools/external_services_config/flibusta.conf');
		self::assertStringContainsString('fastcgi_param HTTP_AUTHORIZATION $http_authorization;', $nginx);
		self::assertStringContainsString('fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;', $nginx);
		self::assertStringNotContainsString('auth_basic ', $nginx);
	}

	public function testDocumentationCoversCurrentOperationalConfiguration(): void {
		$readme = $this->contents($this->root() . '/README.md');
		foreach (['app_scan_books.php', 'libavtoraliase', 'pg_trgm', 'FLIBUSTA_OIDC_CLIENT_SECRET_FILE', '/auth.php', 'HTTP Basic', 'FLIBUSTA_SMTP_PASSWORD_FILE', 'Calibre', 'FLIBUSTA_COMPILATION_RETENTION_SECONDS', 'tests/run.sh'] as $required) {
			self::assertStringContainsString($required, $readme);
		}
	}
}
