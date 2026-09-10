<?php

function author_search_normalize(string $value): string {
	$value = str_replace(['Ё', 'ё'], ['Е', 'е'], $value);
	$value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
	return mb_strtolower($value, 'UTF-8');
}

function author_search_variants(object $author): array {
	$parts = [];
	foreach (['lastname', 'firstname', 'middlename', 'nickname'] as $field) {
		$value = trim((string)($author->{$field} ?? ''));
		if ($value !== '') {
			$parts[$field] = $value;
		}
	}
	$names = [
		'name' => implode(' ', $parts),
		'reverse' => implode(' ', array_filter([
			$parts['firstname'] ?? '', $parts['middlename'] ?? '', $parts['lastname'] ?? '', $parts['nickname'] ?? '',
		])),
	];
	$words = array_values($parts);
	sort($words, SORT_NATURAL | SORT_FLAG_CASE);
	$names['words'] = implode(' ', $words);
	$result = [];
	foreach ($names as $source => $name) {
		$name = author_search_normalize($name);
		if ($name !== '') {
			$result[$source] = $name;
		}
	}
	return $result;
}

function author_search_canonical_id(array $authors, int $author_id): int {
	if (!isset($authors[$author_id])) {
		return $author_id;
	}
	$path = [];
	$current = $author_id;
	while (isset($authors[$current])) {
		$cycle = array_search($current, $path, true);
		if ($cycle !== false) {
			$members = array_slice($path, $cycle);
			sort($members, SORT_NUMERIC);
			return $members[0];
		}
		$path[] = $current;
		$next = (int)($authors[$current]->masterid ?? 0);
		if ($next <= 0) {
			return $current;
		}
		if (!isset($authors[$next])) {
			return $current;
		}
		$current = $next;
	}
	return $author_id;
}

function author_search_load_authors(PDO $dbh): array {
	$authors = [];
	$rows = $dbh->query('SELECT avtorid, lastname, firstname, middlename, nickname, masterid FROM libavtorname');
	while ($author = $rows->fetch(PDO::FETCH_OBJ)) {
		$authors[(int)$author->avtorid] = $author;
	}
	try {
		$aliases = $dbh->query('SELECT badid, goodid FROM libavtoraliase');
		while ($alias = $aliases->fetch(PDO::FETCH_OBJ)) {
			$bad_id = (int)$alias->badid;
			$good_id = (int)$alias->goodid;
			if (isset($authors[$bad_id]) && isset($authors[$good_id]) && (int)$authors[$bad_id]->masterid === 0) {
				$authors[$bad_id]->masterid = $good_id;
			}
		}
	} catch (PDOException $error) {
		// Some source dumps do not include the legacy alias table.
	}
	return $authors;
}

function author_search_rebuild(PDO $dbh): void {
	$authors = author_search_load_authors($dbh);
	$dbh->exec('DELETE FROM author_search_index');
	$insert = $dbh->prepare('INSERT INTO author_search_index (author_id, canonical_author_id, normalized_name, source) VALUES (:author_id, :canonical_author_id, :normalized_name, :source) ON CONFLICT DO NOTHING');
	foreach ($authors as $author_id => $author) {
		$canonical_id = author_search_canonical_id($authors, $author_id);
		foreach (author_search_variants($author) as $source => $name) {
			$insert->execute([
				':author_id' => $author_id,
				':canonical_author_id' => $canonical_id,
				':normalized_name' => $name,
				':source' => $source,
			]);
		}
	}
}

function author_search_query_words(string $query): string {
	$words = preg_split('/\s+/u', author_search_normalize($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
	sort($words, SORT_NATURAL | SORT_FLAG_CASE);
	return implode(' ', $words);
}

function author_search_results(PDO $dbh, string $query, int $limit = 50, int $offset = 0): array {
	$query = author_search_normalize($query);
	if ($query === '') {
		return [];
	}
	try {
		$sql = "WITH input AS (SELECT CAST(:query AS text) AS query, CAST(:words AS text) AS words), matches AS (
			SELECT index_row.canonical_author_id,
				MAX(CASE WHEN index_row.normalized_name IN (input.query, input.words) THEN 1 ELSE 0 END) AS exact_match,
				MAX(GREATEST(similarity(index_row.normalized_name, input.query), similarity(index_row.normalized_name, input.words))) AS score
			FROM author_search_index index_row CROSS JOIN input
			WHERE index_row.normalized_name IN (input.query, input.words)
				OR index_row.normalized_name % input.query OR index_row.normalized_name % input.words
			GROUP BY index_row.canonical_author_id
		)
		SELECT matches.canonical_author_id AS author_id, name.lastname, name.firstname, name.middlename, name.nickname,
			COUNT(DISTINCT CASE WHEN book.deleted = '0' THEN book.bookid END) AS book_count,
			matches.exact_match, matches.score
		FROM matches
		LEFT JOIN libavtorname name ON name.avtorid = matches.canonical_author_id
		LEFT JOIN author_search_index linked ON linked.canonical_author_id = matches.canonical_author_id
		LEFT JOIN libavtor author_book ON author_book.avtorid = linked.author_id
		LEFT JOIN libbook book ON book.bookid = author_book.bookid
		GROUP BY matches.canonical_author_id, name.lastname, name.firstname, name.middlename, name.nickname, matches.exact_match, matches.score
		HAVING COUNT(DISTINCT CASE WHEN book.deleted = '0' THEN book.bookid END) > 0
		ORDER BY matches.exact_match DESC, matches.score DESC, matches.canonical_author_id
		LIMIT :limit OFFSET :offset";
		$stmt = $dbh->prepare($sql);
		$stmt->bindValue(':query', $query);
		$stmt->bindValue(':words', author_search_query_words($query));
		$stmt->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
		$stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
		$stmt->execute();
		return $stmt->fetchAll(PDO::FETCH_OBJ);
	} catch (PDOException $error) {
		return [];
	}
}

function author_search_count(PDO $dbh, string $query): int {
	$query = author_search_normalize($query);
	if ($query === '') {
		return 0;
	}
	try {
		$sql = "WITH input AS (SELECT CAST(:query AS text) AS query, CAST(:words AS text) AS words), matches AS (
			SELECT index_row.canonical_author_id
			FROM author_search_index index_row CROSS JOIN input
			WHERE index_row.normalized_name IN (input.query, input.words)
				OR index_row.normalized_name % input.query OR index_row.normalized_name % input.words
			GROUP BY index_row.canonical_author_id
		)
		SELECT COUNT(*) FROM (
			SELECT matches.canonical_author_id
			FROM matches
			LEFT JOIN author_search_index linked ON linked.canonical_author_id = matches.canonical_author_id
			LEFT JOIN libavtor author_book ON author_book.avtorid = linked.author_id
			LEFT JOIN libbook book ON book.bookid = author_book.bookid
			GROUP BY matches.canonical_author_id
			HAVING COUNT(DISTINCT CASE WHEN book.deleted = '0' THEN book.bookid END) > 0
		) AS matching_authors";
		$stmt = $dbh->prepare($sql);
		$stmt->execute([':query' => $query, ':words' => author_search_query_words($query)]);
		return (int)$stmt->fetchColumn();
	} catch (PDOException $error) {
		return 0;
	}
}

function author_search_linked_ids(PDO $dbh, int $author_id): array {
	try {
		$canonical = $dbh->prepare('SELECT canonical_author_id FROM author_search_index WHERE author_id = :author_id LIMIT 1');
		$canonical->execute([':author_id' => $author_id]);
		$canonical_id = (int)($canonical->fetchColumn() ?: $author_id);
		$linked = $dbh->prepare('SELECT DISTINCT author_id FROM author_search_index WHERE canonical_author_id = :canonical_author_id ORDER BY author_id');
		$linked->execute([':canonical_author_id' => $canonical_id]);
		$ids = array_map('intval', $linked->fetchAll(PDO::FETCH_COLUMN));
		if ($ids !== []) {
			return [$canonical_id, array_values(array_unique($ids))];
		}
	} catch (PDOException $error) {
		// The application remains usable while an import is being initialized.
	}
	return [$author_id, [$author_id]];
}

function author_search_placeholders(array $ids, string $prefix): array {
	$parameters = [];
	$placeholders = [];
	foreach (array_values(array_unique(array_map('intval', $ids))) as $index => $id) {
		$key = ':' . $prefix . $index;
		$placeholders[] = $key;
		$parameters[$key] = $id;
	}
	return ['sql' => implode(', ', $placeholders), 'parameters' => $parameters];
}

function author_search_matching_ids(PDO $dbh, string $query): array {
	$ids = [];
	foreach (author_search_results($dbh, $query, 100, 0) as $result) {
		[, $linked] = author_search_linked_ids($dbh, (int)$result->author_id);
		$ids = array_merge($ids, $linked);
	}
	return array_values(array_unique(array_map('intval', $ids)));
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
	require_once __DIR__ . '/dbinit.php';
	author_search_rebuild($dbh);
}
