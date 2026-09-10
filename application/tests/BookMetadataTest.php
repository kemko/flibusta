<?php

use PHPUnit\Framework\TestCase;

final class BookMetadataTest extends TestCase {
	private string $directory;

	protected function setUp(): void {
		$this->directory = sys_get_temp_dir() . '/flibusta-metadata-' . bin2hex(random_bytes(6));
		mkdir($this->directory);
	}

	protected function tearDown(): void {
		foreach (glob($this->directory . '/*') ?: [] as $file) {
			unlink($file);
		}
		rmdir($this->directory);
	}

	public function testReadsFb2TranslatorsAndCountsUniqueNonCoverImages(): void {
		$data = file_get_contents(__DIR__ . '/fixtures/books/namespaced.fb2');
		$metadata = book_metadata_extract('fb2', $data);

		self::assertSame(['Иван Переводчиков'], $metadata['translators']);
		self::assertSame(2, $metadata['illustration_count']);
		self::assertSame('image/jpeg', $metadata['cover_mime']);
	}

	public function testRejectsExternalEntities(): void {
		$this->expectException(BookMetadataException::class);
		book_metadata_extract('fb2', '<!DOCTYPE x [<!ENTITY x SYSTEM "file:///etc/passwd">]><FictionBook>&x;</FictionBook>');
	}

	public function testReadsDeclaredNonUtf8Encoding(): void {
		$xml = '<?xml version="1.0" encoding="windows-1251"?><FictionBook><description><title-info><translator><first-name>Иван</first-name><last-name>Тест</last-name></translator></title-info></description></FictionBook>';
		$data = iconv('UTF-8', 'Windows-1251', $xml);

		self::assertSame(['Иван Тест'], book_metadata_extract('fb2', $data)['translators']);
	}

	public function testReadsEpub2GuideEpub3CoverAndTranslatorRole(): void {
		$path = $this->directory . '/book.epub';
		$zip = new ZipArchive();
		self::assertTrue($zip->open($path, ZipArchive::CREATE) === true);
		$zip->addFromString('META-INF/container.xml', '<?xml version="1.0"?><container xmlns="urn:oasis:names:tc:opendocument:xmlns:container"><rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');
		$zip->addFromString('OEBPS/content.opf', '<?xml version="1.0"?><package xmlns="http://www.idpf.org/2007/opf" xmlns:dc="http://purl.org/dc/elements/1.1/"><metadata><dc:creator opf:role="trl" xmlns:opf="http://www.idpf.org/2007/opf">Translator</dc:creator><meta name="cover" content="cover"/></metadata><manifest><item id="cover" href="images/cover.jpg" media-type="image/jpeg" properties="cover-image"/><item id="picture" href="images/picture.png" media-type="image/png"/></manifest><guide><reference type="cover" href="cover.xhtml"/></guide></package>');
		$zip->addFromString('OEBPS/cover.xhtml', '<html xmlns="http://www.w3.org/1999/xhtml"><body><img src="images/cover.jpg"/></body></html>');
		$zip->addFromString('OEBPS/images/cover.jpg', "\xFF\xD8\xFF\xD9");
		$zip->addFromString('OEBPS/images/picture.png', "\x89PNG\r\n\x1A\n");
		$zip->close();

		$metadata = book_metadata_extract('epub', file_get_contents($path));

		self::assertSame(['Translator'], $metadata['translators']);
		self::assertSame(1, $metadata['illustration_count']);
		self::assertSame('image/jpeg', $metadata['cover_mime']);
	}

	public function testReadsEpub3RefinedTranslatorRole(): void {
		$path = $this->directory . '/epub3.epub';
		$zip = new ZipArchive();
		self::assertTrue($zip->open($path, ZipArchive::CREATE) === true);
		$zip->addFromString('META-INF/container.xml', '<container><rootfiles><rootfile full-path="content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');
		$zip->addFromString('content.opf', '<package><metadata><creator id="translator">EPUB 3 Translator</creator><meta property="role" refines="#translator">trl</meta></metadata><manifest/></package>');
		$zip->close();

		self::assertSame(['EPUB 3 Translator'], book_metadata_extract('epub', file_get_contents($path))['translators']);
	}

	public function testRejectsCorruptArchivesAndUnsafeEpubPaths(): void {
		try {
			book_metadata_extract('epub', 'not a zip');
			self::fail('Corrupt EPUB was accepted');
		} catch (BookMetadataException $error) {
			self::assertStringContainsString('EPUB', $error->getMessage());
		}

		$path = $this->directory . '/unsafe.epub';
		$zip = new ZipArchive();
		self::assertTrue($zip->open($path, ZipArchive::CREATE) === true);
		$zip->addFromString('META-INF/container.xml', '<container><rootfiles><rootfile full-path="../content.opf" media-type="application/oebps-package+xml"/></rootfiles></container>');
		$zip->close();
		$this->expectException(BookMetadataException::class);
		book_metadata_extract('epub', file_get_contents($path));
	}
}
