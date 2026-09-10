<?php

use PHPUnit\Framework\TestCase;

final class WebAccessTest extends TestCase {
	public function testEveryDynamicPublicResourceUsesTheWebGate(): void {
		foreach (['index.php', 'save_position.php', 'compilation.php'] as $file) {
			self::assertStringContainsString('flibusta_auth_require_web()', file_get_contents(dirname(__DIR__) . '/public/' . $file), $file);
		}
		foreach (['fb2.php', 'usr.php', 'extract_cover.php', 'extract_author.php', 'extract_usr.php'] as $file) {
			self::assertStringContainsString('flibusta_auth_require_book_access($dbh)', file_get_contents(dirname(__DIR__) . '/public/' . $file), $file);
		}
		self::assertStringContainsString('flibusta_opds_require($dbh)', file_get_contents(dirname(__DIR__) . '/public/opds-opensearch.xml.php'));
	}

	public function testStateChangesArePostAndCsrfProtected(): void {
		$index = file_get_contents(dirname(__DIR__) . '/public/index.php');
		self::assertStringContainsString('flibusta_auth_require_post_csrf()', $index);
		self::assertStringNotContainsString("\$_GET['login_uuid']", $index);
		self::assertStringContainsString('flibusta_auth_post_form', file_get_contents(dirname(__DIR__) . '/functions.php'));
		self::assertStringContainsString('method="post"', file_get_contents(dirname(__DIR__) . '/modules/favlist/index.php'));
		self::assertStringContainsString('flibusta_auth_require_post_csrf()', file_get_contents(dirname(__DIR__) . '/modules/service/index.php'));
	}

	public function testNginxDoesNotExposePrivateDirectories(): void {
		$nginx = file_get_contents('/tmp/nginx.conf');
		self::assertStringContainsString('cache|flibusta|sql|tools|vendor|tests', $nginx);
		self::assertStringContainsString('HTTP_AUTHORIZATION $http_authorization', $nginx);
	}
}
