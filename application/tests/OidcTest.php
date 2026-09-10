<?php

use PHPUnit\Framework\TestCase;

final class OidcTest extends TestCase {
	private array $settings = [
		'issuer' => 'https://issuer.example',
		'client_id' => 'client-id',
		'client_secret' => 'secret',
		'scopes' => ['openid'],
		'session_seconds' => 3600,
		'callback_url' => 'https://library.example/library/auth.php',
		'login_url' => '/library/auth.php',
		'home_url' => '/library/',
	];

	public function testRequiresHttpsAndUsesFixedCallback(): void {
		$config = flibusta_config();
		$config['public_url'] = 'https://library.example';
		$config['webroot'] = '/library';
		$config['oidc']['issuer'] = 'https://issuer.example/';
		$config['oidc']['client_id'] = 'client-id';
		$settings = flibusta_auth_settings($config);
		self::assertSame('https://library.example/library/auth.php', $settings['callback_url']);
		$config['public_url'] = 'http://library.example';
		$this->expectException(FlibustaAuthException::class);
		flibusta_auth_settings($config);
	}

	public function testRejectsInvalidOrExpiredClaims(): void {
		$valid = (object)['iss' => 'https://issuer.example', 'aud' => ['client-id'], 'sub' => 'subject', 'exp' => time() + 60];
		flibusta_auth_validate_claims($valid, $this->settings);
		foreach (['iss' => 'https://other.example', 'aud' => ['other'], 'sub' => '', 'exp' => time() - 1] as $field => $value) {
			$claims = clone $valid;
			$claims->$field = $value;
			try {
				flibusta_auth_validate_claims($claims, $this->settings);
				self::fail("$field must be rejected");
			} catch (FlibustaAuthException $error) {
				self::assertSame('OIDC claims are invalid', $error->getMessage());
			}
		}
	}

	public function testConfiguresLibraryForAuthorizationCodePkce(): void {
		$client = flibusta_auth_client($this->settings);
		self::assertSame('S256', $client->getCodeChallengeMethod());
		self::assertContains('openid', $client->getScopes());
	}

	public function testLoginRotatesSessionAndCsrfIsBoundToSession(): void {
		$_SESSION = ['openid_connect_state' => 'old'];
		flibusta_auth_complete_login((object)['iss' => 'https://issuer.example', 'aud' => ['client-id'], 'sub' => 'subject', 'exp' => time() + 60], $this->settings);
		self::assertTrue(flibusta_auth_is_authenticated());
		self::assertTrue(flibusta_auth_check_csrf(flibusta_auth_csrf_token()));
		self::assertArrayNotHasKey('openid_connect_state', $_SESSION);
		self::assertStringContainsString('session_regenerate_id(true)', file_get_contents(dirname(__DIR__) . '/auth.php'));
	}
}
