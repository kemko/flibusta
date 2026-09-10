<?php

require_once __DIR__ . '/book_files.php';

class BookMetadataException extends RuntimeException {
}

function book_metadata_xml(string $xml): DOMDocument {
	if (stripos($xml, '<!DOCTYPE') !== false) {
		throw new BookMetadataException('DOCTYPE is not allowed');
	}
	$previous = libxml_use_internal_errors(true);
	$document = new DOMDocument();
	$document->resolveExternals = false;
	$document->substituteEntities = false;
	$loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
	libxml_clear_errors();
	libxml_use_internal_errors($previous);
	if (!$loaded) {
		throw new BookMetadataException('Invalid XML');
	}
	return $document;
}

function book_metadata_attribute(DOMElement $element, string $name): ?string {
	foreach ($element->attributes as $attribute) {
		if ($attribute->localName === $name) {
			return $attribute->value;
		}
	}
	return null;
}

function book_metadata_person(DOMElement $element): string {
	$parts = [];
	foreach (['first-name', 'middle-name', 'last-name', 'nickname'] as $name) {
		foreach ($element->childNodes as $child) {
			if ($child instanceof DOMElement && $child->localName === $name && trim($child->textContent) !== '') {
				$parts[] = trim($child->textContent);
			}
		}
	}
	return trim(implode(' ', $parts)) ?: trim($element->textContent);
}

function book_metadata_image_mime(string $data): ?string {
	if (str_starts_with($data, "\xFF\xD8\xFF")) {
		return 'image/jpeg';
	}
	if (str_starts_with($data, "\x89PNG\r\n\x1A\n")) {
		return 'image/png';
	}
	if (strlen($data) >= 12 && substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') {
		return 'image/webp';
	}
	return null;
}

function book_metadata_result(array $translators, array $images, array $cover_ids): array {
	$translators = array_values(array_unique(array_filter(array_map('trim', $translators))));
	$images = array_filter($images, static fn ($data) => book_metadata_image_mime($data) !== null);
	$cover_ids = array_values(array_intersect(array_keys($images), $cover_ids));
	$cover_data = $cover_ids === [] ? null : $images[$cover_ids[0]];
	return [
		'translators' => $translators,
		'illustration_count' => count($images) - count($cover_ids),
		'cover_data' => $cover_data,
		'cover_mime' => $cover_data === null ? null : book_metadata_image_mime($cover_data),
	];
}

function book_metadata_fb2(string $data): array {
	$document = book_metadata_xml($data);
	$xpath = new DOMXPath($document);
	$translators = [];
	foreach ($xpath->query('//*[local-name()="translator"]') as $translator) {
		$translators[] = book_metadata_person($translator);
	}
	$cover_ids = [];
	foreach ($xpath->query('//*[local-name()="coverpage"]//*[local-name()="image"]') as $image) {
		$href = book_metadata_attribute($image, 'href');
		if ($href !== null && str_starts_with($href, '#')) {
			$cover_ids[] = substr($href, 1);
		}
	}
	$images = [];
	foreach ($xpath->query('//*[local-name()="binary"]') as $binary) {
		$id = book_metadata_attribute($binary, 'id');
		if ($id === null || $id === '') {
			continue;
		}
		$decoded = base64_decode(preg_replace('/\s+/', '', $binary->textContent), true);
		if ($decoded !== false && book_metadata_image_mime($decoded) !== null) {
			$images[$id] = $decoded;
		}
	}
	return book_metadata_result($translators, $images, $cover_ids);
}

function book_metadata_zip_path(string $path): bool {
	return book_file_zip_name($path);
}

function book_metadata_resolve_path(string $base, string $href): ?string {
	$href = rawurldecode(explode('#', $href, 2)[0]);
	if ($href === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $href) || str_starts_with($href, '/')) {
		return null;
	}
	$parts = [];
	foreach (explode('/', trim($base . '/' . $href, '/')) as $part) {
		if ($part === '' || $part === '.') {
			continue;
		}
		if ($part === '..') {
			if ($parts === []) {
				return null;
			}
			array_pop($parts);
			continue;
		}
		$parts[] = $part;
	}
	$path = implode('/', $parts);
	return book_metadata_zip_path($path) ? $path : null;
}

function book_metadata_epub_entry(ZipArchive $zip, string $path, int $limit): string {
	if (!book_metadata_zip_path($path)) {
		throw new BookMetadataException('Unsafe EPUB path');
	}
	try {
		return book_file_zip_contents($zip, $path, $limit);
	} catch (BookFileException $error) {
		throw new BookMetadataException($error->getMessage(), 0, $error);
	}
}

function book_metadata_epub(string $data, array $limits = []): array {
	$limits = book_file_limits($limits);
	if (strlen($data) > $limits['archive_bytes']) {
		throw new BookMetadataException('EPUB exceeds size limit');
	}
	$configured_tmp = function_exists('flibusta_config') ? flibusta_config()['directories']['tmp'] : null;
	$tmp_directory = is_string($configured_tmp) && is_dir($configured_tmp) ? $configured_tmp : sys_get_temp_dir();
	$tmp_base = realpath($tmp_directory);
	$tmp = $tmp_base === false ? false : tempnam($tmp_base, 'flibusta-epub-');
	if ($tmp !== false && !str_starts_with($tmp, $tmp_base . DIRECTORY_SEPARATOR)) {
		unlink($tmp);
		$tmp = false;
	}
	if ($tmp === false || file_put_contents($tmp, $data, LOCK_EX) === false) {
		throw new BookMetadataException('Cannot create temporary EPUB');
	}
	$zip = new ZipArchive();
	$opened = false;
	try {
		if ($zip->open($tmp, ZipArchive::RDONLY) !== true) {
			throw new BookMetadataException('Invalid EPUB archive');
		}
		$opened = true;
		$container = book_metadata_xml(book_metadata_epub_entry($zip, 'META-INF/container.xml', $limits['entry_bytes']));
		$xpath = new DOMXPath($container);
		$rootfile = $xpath->query('//*[local-name()="rootfile" and @media-type="application/oebps-package+xml"]')->item(0);
		if (!$rootfile instanceof DOMElement) {
			throw new BookMetadataException('EPUB package is missing');
		}
		$opf_path = book_metadata_attribute($rootfile, 'full-path');
		if ($opf_path === null || !book_metadata_zip_path($opf_path)) {
			throw new BookMetadataException('Unsafe EPUB package path');
		}
		$opf = book_metadata_xml(book_metadata_epub_entry($zip, $opf_path, $limits['entry_bytes']));
		$xpath = new DOMXPath($opf);
		$opf_dir = dirname($opf_path) === '.' ? '' : dirname($opf_path);
		$translators = [];
		$people = [];
		foreach ($xpath->query('//*[local-name()="creator" or local-name()="contributor"]') as $person) {
			$id = book_metadata_attribute($person, 'id');
			if ($id !== null) {
				$people[$id] = trim($person->textContent);
			}
			if (strtolower((string)book_metadata_attribute($person, 'role')) === 'trl') {
				$translators[] = trim($person->textContent);
			}
		}
		foreach ($xpath->query('//*[local-name()="meta"]') as $meta) {
			if (strtolower((string)book_metadata_attribute($meta, 'property')) !== 'role' || strtolower(trim($meta->textContent)) !== 'trl') {
				continue;
			}
			$refines = book_metadata_attribute($meta, 'refines');
			if ($refines !== null && str_starts_with($refines, '#') && isset($people[substr($refines, 1)])) {
				$translators[] = $people[substr($refines, 1)];
			}
		}
		$manifest = [];
		foreach ($xpath->query('//*[local-name()="manifest"]/*[local-name()="item"]') as $item) {
			$id = book_metadata_attribute($item, 'id');
			$href = book_metadata_attribute($item, 'href');
			if ($id === null || $href === null || ($path = book_metadata_resolve_path($opf_dir, $href)) === null) {
				continue;
			}
			$manifest[$id] = ['path' => $path, 'properties' => (string)book_metadata_attribute($item, 'properties')];
		}
		$cover_ids = [];
		foreach ($xpath->query('//*[local-name()="meta"]') as $meta) {
			if (strtolower((string)book_metadata_attribute($meta, 'name')) === 'cover' && ($id = book_metadata_attribute($meta, 'content')) !== null) {
				$cover_ids[] = $id;
			}
		}
		foreach ($manifest as $id => $item) {
			if (preg_match('/(^|\s)cover-image(\s|$)/', $item['properties'])) {
				$cover_ids[] = $id;
			}
		}
		$guide_hrefs = [];
		foreach ($xpath->query('//*[local-name()="guide"]//*[local-name()="reference"]') as $reference) {
			if (strtolower((string)book_metadata_attribute($reference, 'type')) === 'cover' && ($href = book_metadata_attribute($reference, 'href')) !== null) {
				$guide_hrefs[] = $href;
			}
		}
		$images = [];
		foreach ($manifest as $id => $item) {
			try {
				$image = book_metadata_epub_entry($zip, $item['path'], $limits['entry_bytes']);
			} catch (BookMetadataException $error) {
				continue;
			}
			if (book_metadata_image_mime($image) !== null) {
				$images[$id] = $image;
			}
		}
		foreach ($guide_hrefs as $href) {
			$path = book_metadata_resolve_path($opf_dir, $href);
			if ($path === null) {
				continue;
			}
			foreach ($manifest as $id => $item) {
				if ($item['path'] === $path) {
					$cover_ids[] = $id;
				}
			}
			try {
				$cover_document = book_metadata_xml(book_metadata_epub_entry($zip, $path, $limits['entry_bytes']));
				$cover_xpath = new DOMXPath($cover_document);
				foreach ($cover_xpath->query('//*[local-name()="img"]') as $image) {
					$src = book_metadata_attribute($image, 'src');
					if ($src === null || ($image_path = book_metadata_resolve_path(dirname($path), $src)) === null) {
						continue;
					}
					foreach ($manifest as $id => $item) {
						if ($item['path'] === $image_path) {
							$cover_ids[] = $id;
						}
					}
				}
			} catch (BookMetadataException $error) {
				continue;
			}
		}
		return book_metadata_result($translators, $images, $cover_ids);
	} finally {
		if ($opened) {
			$zip->close();
		}
		unlink($tmp);
	}
}

function book_metadata_extract(string $format, string $data, array $limits = []): array {
	return match (strtolower($format)) {
		'fb2' => book_metadata_fb2($data),
		'epub' => book_metadata_epub($data, $limits),
		default => throw new BookMetadataException('Unsupported book format'),
	};
}

function book_metadata_for_file(array $book_file, array $limits = []): array {
	return book_metadata_extract((string)$book_file['format'], book_file_contents($book_file, $limits), $limits);
}
