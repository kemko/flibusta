<?php

use PHPUnit\Framework\TestCase;

final class CompilationTest extends TestCase {
	private function compilation(): DOMDocument {
		$fixture = __DIR__ . '/fixtures/books/';
		$xml = compilation_build('Мой сборник', [
			[
				'bookid' => 10,
				'format' => 'fb2',
				'fb2' => file_get_contents($fixture . 'compilation-one.fb2'),
				'authors' => ['Автор Один', 'Автор Общий'],
				'primary_translators' => ['Основной Переводчик'],
				'extracted_translators' => ['Не должен попасть'],
			],
			[
				'bookid' => 20,
				'format' => 'epub',
				'fb2' => file_get_contents($fixture . 'compilation-two.fb2'),
				'authors' => ['Автор Общий', 'Автор Два'],
				'extracted_translators' => ['Извлечённый Переводчик'],
			],
		], 'https://library.example');
		$document = new DOMDocument();
		self::assertTrue($document->loadXML($xml));
		return $document;
	}

	public function testBuildsOrderedNestedBodiesAndRewritesReferences(): void {
		$document = $this->compilation();
		$xpath = new DOMXPath($document);
		self::assertSame('Первое произведение', $xpath->evaluate('string(/*[local-name()="FictionBook"]/*[local-name()="body"][1]/*[local-name()="section"][1]/*[local-name()="title"]/*[local-name()="p"])'));
		self::assertSame('Второе произведение', $xpath->evaluate('string(/*[local-name()="FictionBook"]/*[local-name()="body"][1]/*[local-name()="section"][2]/*[local-name()="title"]/*[local-name()="p"])'));
		self::assertSame(1, (int)$xpath->evaluate('count(//*[local-name()="section" and @id="book-10-1-work"]/*[local-name()="section" and @id="book-10-1-chapter"])'));
		self::assertSame(0, (int)$xpath->evaluate('count(//*[local-name()="section" and @id="book-20-2-work"]/*[local-name()="section"])'));
		self::assertSame(1, (int)$xpath->evaluate('count(//*[@*[local-name()="href"]="#book-10-1-note"])'));
		self::assertSame(2, (int)$xpath->evaluate('count(/*[local-name()="FictionBook"]/*[local-name()="binary"])'));
	}

	public function testCombinesMetadataAndSourcesAndValidatesXsd(): void {
		$document = $this->compilation();
		$xpath = new DOMXPath($document);
		self::assertSame(3, (int)$xpath->evaluate('count(//*[local-name()="title-info"]/*[local-name()="author"])'));
		self::assertSame(['Основной Переводчик', 'Извлечённый Переводчик'], array_map(static fn (DOMElement $element) => trim($element->textContent), iterator_to_array($xpath->query('//*[local-name()="title-info"]/*[local-name()="translator"]'))));
		self::assertStringContainsString('Первое произведение; Второе произведение', $xpath->evaluate('string(//*[local-name()="annotation"])'));
		self::assertSame(1, (int)$xpath->evaluate('count(//*[local-name()="section"]/*[local-name()="title"]/*[local-name()="p" and text()="Источники"])'));
		self::assertStringContainsString('https://library.example/book/view/10', $document->saveXML());
		compilation_validate($document, dirname(__DIR__) . '/schema/FictionBook.xsd');
	}

	public function testPreservesBodyTitlesAndMultipleNotesBodies(): void {
		$source = str_replace('<body><section', '<body><title><p>Fancy title</p></title><section', file_get_contents(__DIR__ . '/fixtures/books/compilation-one.fb2'));
		$source = str_replace('<body name="notes">', '<body name="notes"><title><p>Notes title</p></title>', $source);
		$result = compilation_build('Two books', [['fb2' => $source], ['fb2' => $source]]);
		self::assertSame(2, substr_count($result, 'Fancy title'));
		self::assertSame(2, substr_count($result, 'Notes title'));
		self::assertSame(1, substr_count($result, '<body name="notes">'));
	}

	public function testRejectsStructurallyInvalidFb2(): void {
		$document = new DOMDocument();
		$document->loadXML('<FictionBook xmlns="http://www.gribuser.ru/xml/fictionbook/2.0"><anything/></FictionBook>');
		$this->expectException(CompilationException::class);
		compilation_validate($document);
	}

	public function testRejectsBrokenSourceReference(): void {
		$this->expectException(CompilationException::class);
		compilation_build('Сборник', [[
			'bookid' => 1,
			'data' => '<FictionBook><body><p><a href="#missing">x</a></p></body></FictionBook>',
		]]);
	}
}
