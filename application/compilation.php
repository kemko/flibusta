<?php

require_once __DIR__ . '/book_metadata.php';

class CompilationException extends RuntimeException {
}

const COMPILATION_FB2_NS = 'http://www.gribuser.ru/xml/fictionbook/2.0';
const COMPILATION_XLINK_NS = 'http://www.w3.org/1999/xlink';

function compilation_element(DOMDocument $document, string $name, ?string $text = null): DOMElement {
	$element = $document->createElementNS(COMPILATION_FB2_NS, $name);
	if ($text !== null) {
		$element->appendChild($document->createTextNode($text));
	}
	return $element;
}

function compilation_values(array $values): array {
	$result = [];
	foreach ($values as $value) {
		$value = trim((string)$value);
		if ($value === '') {
			continue;
		}
		$key = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
		$key = preg_replace('/\s+/u', ' ', $key);
		$result[$key] = $value;
	}
	return array_values($result);
}

function compilation_people(DOMDocument $document, DOMElement $parent, string $type, array $names): void {
	foreach (compilation_values($names) as $name) {
		$person = compilation_element($document, $type);
		$person->appendChild(compilation_element($document, 'nickname', $name));
		$parent->appendChild($person);
	}
}

function compilation_book_translators(array $book): array {
	$primary = $book['primary_translators'] ?? $book['translators'] ?? [];
	return compilation_values($primary !== [] ? (array)$primary : (array)($book['extracted_translators'] ?? []));
}

function compilation_book_fb2(array $book): string {
	$fb2 = $book['fb2'] ?? null;
	if ($fb2 === null && strtolower((string)($book['format'] ?? 'fb2')) === 'fb2') {
		$fb2 = $book['data'] ?? null;
	}
	if (!is_string($fb2) || $fb2 === '') {
		throw new CompilationException('Book source must be converted to FB2');
	}
	return $fb2;
}

function compilation_source_title(DOMDocument $source, array $book): string {
	$title = trim((string)($book['title'] ?? ''));
	if ($title !== '') {
		return $title;
	}
	$xpath = new DOMXPath($source);
	$value = $xpath->evaluate('string(/*[local-name()="FictionBook"]/*[local-name()="description"]//*[local-name()="title-info"]/*[local-name()="book-title"])');
	return trim((string)$value) ?: 'Без названия';
}

function compilation_prefix_source_ids(DOMElement $root, string $prefix): array {
	$ids = [];
	foreach ([$root, ...iterator_to_array($root->getElementsByTagName('*'))] as $element) {
		foreach ($element->attributes as $attribute) {
			if ($attribute->localName !== 'id') {
				continue;
			}
			$id = $attribute->value;
			if ($id === '' || isset($ids[$id])) {
				throw new CompilationException('Duplicate or empty source identifier');
			}
			$ids[$id] = $prefix . $id;
		}
	}
	foreach ([$root, ...iterator_to_array($root->getElementsByTagName('*'))] as $element) {
		foreach ($element->attributes as $attribute) {
			if ($attribute->localName === 'id') {
				$element->setAttributeNS($attribute->namespaceURI, $attribute->nodeName, $ids[$attribute->value]);
				continue;
			}
			if ($attribute->localName === 'href' && str_starts_with($attribute->value, '#')) {
				$id = substr($attribute->value, 1);
				if (!isset($ids[$id])) {
					throw new CompilationException('Broken source reference: ' . $attribute->value);
				}
				$element->setAttributeNS($attribute->namespaceURI, $attribute->nodeName, '#' . $ids[$id]);
			}
		}
	}
	return $ids;
}

function compilation_main_body(DOMDocument $source): DOMElement {
	foreach ($source->documentElement->childNodes as $node) {
		if ($node instanceof DOMElement && $node->localName === 'body' && !$node->hasAttribute('name')) {
			return $node;
		}
	}
	throw new CompilationException('FB2 has no main body');
}

function compilation_copy_body(DOMDocument $document, DOMElement $body, DOMElement $target): void {
	// A body orders its opening image before its title; a section reverses them.
	$children = array_values(array_filter(iterator_to_array($body->childNodes), static fn ($node): bool => $node instanceof DOMElement));
	foreach (['title', 'epigraph', 'image'] as $name) {
		foreach ($children as $node) {
			if ($node->localName === $name) {
				$target->appendChild($document->importNode($node, true));
			}
		}
	}
	foreach ($children as $node) {
		if (!in_array($node->localName, ['title', 'epigraph', 'image'], true)) {
			$target->appendChild($document->importNode($node, true));
		}
	}
}

function compilation_validate(DOMDocument $document, ?string $xsd = null): void {
	$ids = [];
	$links = [];
	foreach ($document->getElementsByTagName('*') as $element) {
		foreach ($element->attributes as $attribute) {
			if ($attribute->localName === 'id') {
				if ($attribute->value === '' || isset($ids[$attribute->value])) {
					throw new CompilationException('Duplicate or empty compilation identifier');
				}
				$ids[$attribute->value] = true;
			}
			if ($attribute->localName === 'href' && str_starts_with($attribute->value, '#')) {
				$links[] = $attribute->value;
			}
		}
	}
	foreach ($links as $link) {
		if (!isset($ids[substr($link, 1)])) {
			throw new CompilationException('Broken compilation reference: ' . $link);
		}
	}
	$xsd = $xsd ?? __DIR__ . '/schema/FictionBook.xsd';
	$previous = libxml_use_internal_errors(true);
	$valid = $document->schemaValidate($xsd);
	$validation_error = libxml_get_last_error();
	libxml_clear_errors();
	libxml_use_internal_errors($previous);
	if (!$valid) {
		throw new CompilationException('Compilation fails XSD validation: ' . ($validation_error ? trim($validation_error->message) : 'invalid document'));
	}
}

/**
 * Build a compilation from already converted FB2 sources.
 *
 * Each source accepts bookid, title, authors, primary_translators (or
 * translators), extracted_translators, format and fb2. Raw data is accepted
 * only for an FB2 source; EPUB must first pass through epub_to_fb2.py.
 */
function compilation_build(string $title, array $books, string $webroot = '', ?string $xsd = null): string {
	$title = trim($title);
	if ($title === '' || $books === []) {
		throw new CompilationException('Compilation title and books are required');
	}
	$document = new DOMDocument('1.0', 'UTF-8');
	$document->formatOutput = true;
	$root = $document->createElementNS(COMPILATION_FB2_NS, 'FictionBook');
	$root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:l', COMPILATION_XLINK_NS);
	$document->appendChild($root);
	$description = compilation_element($document, 'description');
	$title_info = compilation_element($document, 'title-info');
	$title_info->appendChild(compilation_element($document, 'genre', 'unrecognised'));
	$book_title = compilation_element($document, 'book-title', $title);
	$title_info->appendChild($book_title);
	$annotation = compilation_element($document, 'annotation');
	$title_info->appendChild($annotation);
	$description->appendChild($title_info);
	$document_info = compilation_element($document, 'document-info');
	compilation_people($document, $document_info, 'author', ['Flibusta']);
	$document_info->appendChild(compilation_element($document, 'program-used', 'Flibusta compilation'));
	$document_info->appendChild(compilation_element($document, 'date', gmdate('Y-m-d')));
	$document_info->appendChild(compilation_element($document, 'id', hash('sha256', serialize([$title, $books]))));
	$document_info->appendChild(compilation_element($document, 'version', '1.0'));
	$description->appendChild($document_info);
	$root->appendChild($description);

	$body = compilation_element($document, 'body');
	$root->appendChild($body);
	$authors = [];
	$translators = [];
	$sources = [];
	$binaries = [];
	$secondary_bodies = [];
	foreach (array_values($books) as $position => $book) {
		if (!is_array($book)) {
			throw new CompilationException('Invalid book source');
		}
		try {
			$source = book_metadata_fb2_document(compilation_book_fb2($book));
		} catch (BookMetadataException $error) {
			throw new CompilationException($error->getMessage(), 0, $error);
		}
		$bookid = (int)($book['bookid'] ?? 0);
		$prefix = 'book-' . ($bookid > 0 ? $bookid : $position + 1) . '-' . ($position + 1) . '-';
		compilation_prefix_source_ids($source->documentElement, $prefix);
		$source_title = compilation_source_title($source, $book);
		$section = compilation_element($document, 'section');
		$section->setAttribute('id', $prefix . 'work');
		$section_title = compilation_element($document, 'title');
		$section_title->appendChild(compilation_element($document, 'p', $source_title));
		$section->appendChild($section_title);
		$main = compilation_main_body($source);
		$has_body_title = false;
		foreach ($main->childNodes as $node) {
			$has_body_title = $has_body_title || ($node instanceof DOMElement && $node->localName === 'title');
		}
		if ($has_body_title) {
			$content = compilation_element($document, 'section');
			compilation_copy_body($document, $main, $content);
			$section->appendChild($content);
		} else {
			foreach ($main->childNodes as $node) {
				$section->appendChild($document->importNode($node, true));
			}
		}
		$body->appendChild($section);
		foreach ($source->documentElement->childNodes as $node) {
			if (!$node instanceof DOMElement) {
				continue;
			}
			if ($node->localName === 'body' && $node->hasAttribute('name')) {
				$secondary_bodies[] = $document->importNode($node, true);
			}
			if ($node->localName === 'binary') {
				$binaries[] = $document->importNode($node, true);
			}
		}
		$authors = [...$authors, ...(array)($book['authors'] ?? [])];
		$book_translators = compilation_book_translators($book);
		$translators = [...$translators, ...$book_translators];
		$sources[] = [
			'title' => $source_title,
			'authors' => compilation_values((array)($book['authors'] ?? [])),
			'translators' => $book_translators,
			'format' => strtoupper((string)($book['format'] ?? 'fb2')),
			'bookid' => $bookid,
			'url' => (string)($book['url'] ?? rtrim($webroot, '/') . '/book/view/' . $bookid),
		];
	}
	compilation_people($document, $title_info, 'author', $authors ?: ['Неизвестный автор']);
	foreach (iterator_to_array($title_info->getElementsByTagName('author')) as $author) {
		$title_info->insertBefore($author, $book_title);
	}
	$title_info->appendChild(compilation_element($document, 'lang', 'und'));
	compilation_people($document, $title_info, 'translator', $translators);
	$annotation->appendChild(compilation_element($document, 'p', 'Сборник произведений: ' . implode('; ', array_column($sources, 'title'))));
	$sources_section = compilation_element($document, 'section');
	$sources_title = compilation_element($document, 'title');
	$sources_title->appendChild(compilation_element($document, 'p', 'Источники'));
	$sources_section->appendChild($sources_title);
	foreach ($sources as $source) {
		$text = $source['title'] . '; авторы: ' . ($source['authors'] === [] ? 'не указаны' : implode(', ', $source['authors'])) . '; переводчики: ' . ($source['translators'] === [] ? 'не указаны' : implode(', ', $source['translators'])) . '; формат: ' . $source['format'] . '; bookid: ' . $source['bookid'] . '; ';
		$paragraph = compilation_element($document, 'p', $text);
		$link = compilation_element($document, 'a', 'Оригинальная карточка');
		$link->setAttributeNS(COMPILATION_XLINK_NS, 'l:href', $source['url']);
		$paragraph->appendChild($link);
		$sources_section->appendChild($paragraph);
	}
	$body->appendChild($sources_section);
	if ($secondary_bodies !== []) {
		$notes = compilation_element($document, 'body');
		$notes->setAttribute('name', 'notes');
		foreach ($secondary_bodies as $secondary_body) {
			$note_group = compilation_element($document, 'section');
			compilation_copy_body($document, $secondary_body, $note_group);
			$notes->appendChild($note_group);
		}
		$root->appendChild($notes);
	}
	foreach ($binaries as $binary) {
		$root->appendChild($binary);
	}
	compilation_validate($document, $xsd);
	return $document->saveXML();
}
