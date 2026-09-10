<?php

use PHPUnit\Framework\TestCase;

final class EpubConversionTest extends TestCase {
	private string $directory;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/flibusta-epub-' . bin2hex(random_bytes(6));
		mkdir($this->directory);
	}

	protected function tearDown(): void {
		foreach (glob($this->directory . '/*') ?: [] as $file) {
			unlink($file);
		}
		rmdir($this->directory);
	}

	public function testInspectsNestedNavigationAndRepeatedTargets(): void {
		$epub = $this->createEpub(false);
		exec('python3 ' . escapeshellarg(dirname(__DIR__) . '/tools/epub_to_fb2.py') . ' --inspect ' . escapeshellarg($epub), $output, $status);

		self::assertSame(0, $status);
		$inspection = json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
		self::assertSame(['OEBPS/chapter.xhtml'], $inspection['spine']);
		self::assertCount(2, $inspection['toc']);
		self::assertSame('OEBPS/chapter.xhtml', $inspection['toc'][0]['target']);
		self::assertSame('OEBPS/chapter.xhtml', $inspection['toc'][1]['children'][0]['target']);
	}

	public function testRejectsBrokenXhtmlLink(): void {
		$epub = $this->createEpub(true);
		exec('python3 ' . escapeshellarg(dirname(__DIR__) . '/tools/epub_to_fb2.py') . ' --inspect ' . escapeshellarg($epub) . ' 2>&1', $output, $status);

		self::assertSame(1, $status);
		self::assertStringContainsString('Broken EPUB link', implode("\n", $output));
	}

	private function createEpub(bool $broken): string {
		$path = $this->directory . '/book.epub';
		$zip = new ZipArchive();
		self::assertTrue($zip->open($path, ZipArchive::CREATE) === true);
		$fixture = __DIR__ . '/fixtures/epub/nested/';
		$zip->addFromString('META-INF/container.xml', file_get_contents($fixture . 'container.xml'));
		$zip->addFromString('OEBPS/content.opf', file_get_contents($fixture . 'content.opf'));
		$chapter = file_get_contents($fixture . 'chapter.xhtml');
		$zip->addFromString('OEBPS/chapter.xhtml', $broken ? str_replace('image.png', 'missing.png', $chapter) : $chapter);
		$zip->addFromString('OEBPS/nav.xhtml', file_get_contents($fixture . 'nav.xhtml'));
		$zip->addFromString('OEBPS/images/image.png', "\x89PNG\r\n\x1A\n");
		$zip->close();
		return $path;
	}
}
