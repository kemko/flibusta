<?php
$stmt = $dbh->prepare("SELECT COUNT(*) cnt FROM fav_users");
$stmt->execute();
$fav_count = $stmt->fetch()->cnt;

if ($fav_count == 0) {
	die("Книжные полки не определены");
}

$stmt = $dbh->prepare("SELECT *
		FROM fav
		LEFT JOIN libavtorname USING(AvtorId)
		LEFT JOIN libapics USING(AvtorId)
		WHERE user_uuid=:uuid AND avtorid IS NOT NULL");
$stmt->bindParam(":uuid", $user_uuid);

try {
	$stmt->execute();
} catch (PDOException $e) {
	//
}

echo '<div class="row">';
while ($a = $stmt->fetch()) {
	[$canonical_author_id] = author_search_linked_ids($dbh, (int)$a->avtorid);
	if ($canonical_author_id !== (int)$a->avtorid) {
		$name = $dbh->prepare('SELECT * FROM libavtorname LEFT JOIN libapics USING(AvtorId) WHERE avtorid = :id');
		$name->execute([':id' => $canonical_author_id]);
		$a = $name->fetch() ?: $a;
	}
	echo "<div class='col col-sm-333 mb-3 d-flex justify-content-between'>";
	echo "<a class='mw-100 rounded-pill author' href='$webroot/author/view/$a->avtorid'>";
	if ($a->file != '') {
		echo "<img class='rounded-circle contact' src='$webroot/extract_author.php?id=$a->avtorid' />";	
	}
	echo "&nbsp;$a->lastname $a->firstname $a->middlename $a->nickname&nbsp;</a>";
	echo "</div>";
}
echo "</div>";


$stmt = $dbh->prepare("SELECT *
		FROM fav
		LEFT JOIN libseqname USING(seqid)
		WHERE user_uuid=:uuid AND seqid IS NOT NULL");
$stmt->bindParam(":uuid", $user_uuid);

echo '<div class="row">';
echo '<div class="col mb-3">';
echo '<div class="block">';
try {
$stmt->execute();
while ($s = $stmt->fetch()) {
	echo "<a class='btn btn-sm btn-dark' href='$webroot/?sid=$s->seqid'>";
	echo "&nbsp;$s->seqname&nbsp;</a> ";
}

} catch (PDOException $e) {
	print_r($e);
}
echo "</div>";
echo "</div>";
echo "</div>";

$stmt = $dbh->prepare("SELECT DISTINCT b.*
		FROM fav f
		LEFT JOIN libbook b USING(bookid)
		WHERE user_uuid=:uuid AND f.bookid IS NOT NULL");
$stmt->bindParam(":uuid", $user_uuid);
$stmt->execute();

$cnt = $stmt->rowCount();

//show_gpager(ceil($cnt / RECORDS_PAGE), 5);

 $book_list = book_presentation_attach_metadata($dbh, $stmt->fetchAll());
echo "<div class='contaner'>";
echo "<div class='row equal'>";

$c = 0;
foreach ($book_list as $book) {
	$c++;
	if ($c > 10) {
//		break;
	}
	book_small_pg($book,$webroot);
}
echo "</div>";
echo "</div>";

//show_gpager(ceil($cnt / RECORDS_PAGE), 5);
