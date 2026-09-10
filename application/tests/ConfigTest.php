<?php

use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase {
	protected function tearDown(): void {
		foreach (['FLIBUSTA_PUBLIC_URL', 'FLIBUSTA_OIDC_CLIENT_SECRET', 'FLIBUSTA_OIDC_CLIENT_SECRET_FILE', 'FLIBUSTA_OPDS_OWNER_HMAC_KEY_FILE', 'FLIBUSTA_MAX_ENTRY_BYTES'] as $name) {
			putenv($name);
		}
	}

	public function testReadsSecretsFromFilesAndLimitsFromEnvironment(): void {
		$file = tempnam(sys_get_temp_dir(), 'flibusta-secret-');
		file_put_contents($file, "secret-value\n");
		putenv('FLIBUSTA_PUBLIC_URL=https://library.example/');
		putenv('FLIBUSTA_OIDC_CLIENT_SECRET_FILE=' . $file);
		putenv('FLIBUSTA_OPDS_OWNER_HMAC_KEY_FILE=' . $file);
		putenv('FLIBUSTA_MAX_ENTRY_BYTES=123');

		$config = flibusta_config();
		self::assertSame('https://library.example', $config['public_url']);
		self::assertSame('secret-value', $config['oidc']['client_secret']);
		self::assertSame('secret-value', $config['opds']['owner_hmac_key']);
		self::assertSame(123, $config['limits']['entry_bytes']);
		unlink($file);
	}

	public function testRejectsInvalidResourceLimit(): void {
		putenv('FLIBUSTA_MAX_ENTRY_BYTES=not-a-number');
		$this->expectException(RuntimeException::class);
		flibusta_config();
	}
}
