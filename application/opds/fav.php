<?php
header('Content-Type: application/atom+xml; charset=utf-8');
echo '<?xml version="1.0" encoding="utf-8"?>';
echo <<< _XML
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/terms/" xmlns:os="http://a9.com/-/spec/opensearch/1.1/" xmlns:opds="http://opds-spec.org/2010/catalog">
<id>tag:root:home</id>
<title>Книги по авторам</title>
_XML;
echo "<updated>$cdt</updated>";
echo <<< _XML
<icon>/favicon.ico</icon>
<link href="$webroot/opds-opensearch.xml.php" rel="search" type="application/opensearchdescription+xml" />
<link href="$webroot/opds/search?q={searchTerms}" rel="search" type="application/atom+xml" />
<link href="$webroot/opds/" rel="start" type="application/atom+xml;profile=opds-catalog" />
_XML;
echo '<link href="' . htmlspecialchars($webroot . '/opds/fav/?uuid=' . rawurlencode($_GET['uuid']), ENT_QUOTES | ENT_XML1, 'UTF-8') . '" rel="self" type="application/atom+xml;profile=opds-catalog" />';
 
$uuid = $_GET['uuid'];
$books = $dbh->prepare("SELECT DISTINCT b.*
		FROM fav f
		LEFT JOIN libbook b USING(bookid)
		WHERE user_uuid=:uuid AND f.bookid IS NOT NULL");
$books->bindParam(":uuid", $uuid);
$books->execute();
$book_list = book_presentation_attach_metadata($dbh, $books->fetchAll());

foreach ($book_list as $b) {
	opds_book($b, $webroot);
}

echo "</feed>";
?>
