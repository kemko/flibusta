<?php
header('Content-Type: application/atom+xml; charset=utf-8');
echo '<?xml version="1.0" encoding="utf-8"?>';
echo <<< _XML
 <feed xmlns="http://www.w3.org/2005/Atom" xmlns:dc="http://purl.org/dc/terms/" xmlns:os="http://a9.com/-/spec/opensearch/1.1/" xmlns:opds="http://opds-spec.org/2010/catalog"> <id>tag:root:authors</id>
 <title>Поиск по авторам</title>
 <updated>$cdt</updated>
 <icon>/favicon.ico</icon>
 <link href="$webroot/opds-opensearch.xml.php" rel="search" type="application/opensearchdescription+xml" />
 <link href="$webroot/opds/authorsindex?letters={searchTerm}" rel="search" type="application/atom+xml" />
 <link href="$webroot/opds" rel="start" type="application/atom+xml;profile=opds-catalog" />


<entry> <updated>$cdt</updated>
 <id>tag:search:author</id>
 <title>Поиск авторов</title>
 <content type="text">Поиск авторов по фамилии</content>
 <link href="$webroot/opds/authorsindex?letters={searchTerm}" type="application/atom+xml;profile=opds-catalog" />
</entry>
_XML;

$q = trim((string)($_GET['q'] ?? ''));

if ($q == '') {
	die(':(');
}
$prefix = ($_GET['prefix'] ?? '') === '1';
$page = max(0, (int)($_GET['page'] ?? 0));
if (author_search_count($dbh, $q, $prefix) > ($page + 1) * OPDS_FEED_COUNT) {
	$next = htmlspecialchars($webroot . '/opds/search?' . http_build_query(['by' => 'author', 'q' => $q, 'prefix' => $prefix ? '1' : '0', 'page' => $page + 1]), ENT_XML1 | ENT_QUOTES, 'UTF-8');
	echo "<link rel='next' href='$next' type='application/atom+xml;profile=opds-catalog' />";
}
foreach (author_search_results($dbh, $q, OPDS_FEED_COUNT, $page * OPDS_FEED_COUNT, $prefix) as $a) {
	if ($a->book_count > 0) {
		echo "\n<entry> <updated>$cdt</updated>";
		echo " <id>tag:author:$a->author_id</id>";
		echo " <title>" . htmlspecialchars("$a->lastname $a->firstname $a->middlename $a->nickname", ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</title>";
		echo " <content type='text'>$a->book_count книг</content>";
		echo " <link href='$webroot/opds/author?author_id=$a->author_id' type='application/atom+xml;profile=opds-catalog' />";
		echo '</entry>';
	}
}


/*
echo "<div class='mdl-color--white mdl-shadow--2dp mdl-cell mdl-cell--12-col mdl-grid'>";
echo '<div class="mdl-grid">';
$seqs = DB::query("SELECT *,
	(SELECT COUNT(*) FROM libseq WHERE libseq.seqid=libseqname.SeqId) cnt
	FROM libseqname $filter4 ORDER BY seqname");

while ($s = $seqs->fetch_object()) {
	if ($s->cnt > 0) {
		echo "<div class='mdl-cell--4-col'>";

		echo "<span class='mdl-chip' onclick=\"location='/library/seq/$s->SeqId'\">";

		echo "<span class='mdl-chip__text'>$s->SeqName</span>";



		echo "<span class='mdl-chip__action'>$s->cnt</span>";

		echo "</span>";

		echo "</div>";
	}
}
*/
?>
</feed>
