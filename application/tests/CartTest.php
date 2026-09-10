<?php

use PHPUnit\Framework\TestCase;

final class CartTest extends TestCase {
	protected function setUp(): void {
		$_SESSION = [];
	}

	public function testCartDeduplicatesAndMovesBooks(): void {
		self::assertTrue(cart_add(10, 3));
		self::assertFalse(cart_add(10, 3));
		cart_add(20, 3);
		cart_add(30, 3);
		cart_move(30, -1);
		self::assertSame([10, 30, 20], cart_items());
		cart_remove(30);
		self::assertSame([10, 20], cart_items());
		$this->expectException(CartException::class);
		cart_add(40, 2);
	}

	public function testRequestTokenIsStableUntilAJobIsCreated(): void {
		$first = cart_request_token();
		self::assertSame($first, cart_request_token());
		self::assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $first);
	}

	public function testCartPresentationUsesAccessiblePostControls(): void {
		$functions = file_get_contents(dirname(__DIR__) . '/functions.php');
		self::assertStringContainsString("'/compilation.php'", $functions);
		self::assertStringContainsString('cart_book_form', $functions);
		$module = file_get_contents(dirname(__DIR__) . '/modules/cart/index.php');
		self::assertStringContainsString('cart_action', $module);
		self::assertStringContainsString('request_token', $module);
	}
}
