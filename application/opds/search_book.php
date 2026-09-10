<?php
header('Content-Type: application/atom+xml; charset=utf-8');
echo '<?xml version="1.0" encoding="utf-8"?>';
echo <<< _XML
 <feed xmlns="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/terms/" xmlns:os="http://a9.com/-/spec/opensearch/1.1/" xmlns:opds="http://opds-spec.org/2010/catalog"> <id>tag:root:authors</id>
 <title>Поиск по книгам</title>
 <updated>$cdt</updated>
 <icon>/favicon.ico</icon>
 <link href="$webroot/opds-opensearch.xml.php" rel="search" type="application/opensearchdescription+xml" />
 <link href="$webroot/opds/search?q={searchTerms}" rel="search" type="application/atom+xml" />
 <link href="$webroot/opds" rel="start" type="application/atom+xml;profile=opds-catalog" />
_XML;

$q = trim((string)($_GET['q'] ?? ''));
if ($q == '') {
	echo '</feed>';
	return;
}

$author_parameters = [];
$author_filter = '';
$author_ids = author_search_matching_ids($dbh, $q);
if ($author_ids !== []) {
	$placeholders = author_search_placeholders($author_ids, 'search_author_');
	$author_filter = ' OR EXISTS (SELECT 1 FROM libavtor search_author WHERE search_author.bookid = libbook.bookid AND search_author.avtorid IN (' . $placeholders['sql'] . '))';
	$author_parameters = $placeholders['parameters'];
}
$books = $dbh->prepare("SELECT libbook.*
		FROM libbook
		WHERE deleted='0' AND (libbook.Title LIKE :q$author_filter)
		ORDER BY bookid
		LIMIT 100");
		$param = '%'.$q.'%';
$books->bindParam(":q", $param);
foreach ($author_parameters as $parameter => $value) {
	$books->bindValue($parameter, $value, PDO::PARAM_INT);
}
$books->execute();

foreach (book_presentation_attach_metadata($dbh, $books->fetchAll()) as $b) {
	opds_book($b, $webroot);
}
$books = null;
?>
</feed>
