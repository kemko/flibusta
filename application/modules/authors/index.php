<style>
.c {
	background: #eee;
	border-radius: 50%;
	border-color: #eee;
}
</style>

<?php

include_once(ROOT_PATH . "webroot.php");
$filter2 = "";
$letter = 'А%';
$get = '';

if (isset($_GET['q'])) {
	$get = mb_strtolower($_GET['q']);
	$letter = '%' . $get;
	$_SESSION['authors_letter'] = $get;
}

if (isset($_SESSION['authors_letter'])) {
	$get = $_SESSION['authors_letter'];
}
if (isset($_GET['letter'])) {
	$get = mb_strtolower($_GET['letter']);
}
if ($get != '') {
	$_SESSION['authors_letter'] = $get;
	$letter = $get . "%";
} else {
	unset($_SESSION['series_letter']);
}

echo "<ul class='pagination'>";
	foreach (range(chr(0xC0), chr(0xDF)) as $b) {
		$l = iconv('CP1251', 'UTF-8', $b);
		if ($l == mb_strtoupper($get)) {
			$cc = 'active';
		} else {
			$cc = '';
		}
		echo "<li class='page-item $cc'><a class='page-link' href='$webroot/authors/?letter=" . urlencode($l) . "'>$l</a></li>";
	}
echo "</ul>";
echo "<ul class='pagination'>";
	foreach (range('A', 'Z') as $b) {
		$l = iconv('CP1251', 'UTF-8', $b);
		if ($l == mb_strtoupper($get)) {
			$cc = 'active';
		} else {
			$cc = '';
		}
		echo "<li class='page-item $cc'><a class='page-link' href='$webroot/authors/?letter=" . urlencode($l) . "'>$l</a></li>";
	}

echo "</ul>";


echo "<form action='$webroot/authors/'>\n";
?>
<div class="input-group mb-3">
  <input name="q" type="text" class="form-control" placeholder="Поиск автора" aria-label="Поиск серии" aria-describedby="basic-addon2">
  <div class="input-group-append">

    <input type='submit' class="btn btn-outline-secondary" value='Поиск' type="button">
  </div>
</div>
</form>

<?php
$start = AUTHORS_PAGE * $page;
$search_query = trim((string)($_GET['q'] ?? ''));
if ($search_query !== '') {
	$authors = author_search_results($dbh, $search_query, AUTHORS_PAGE, $start);
	$cnt = author_search_count($dbh, $search_query);
} else {
	$stmt = $dbh->prepare("SELECT libavtorname.*, libavtorname.avtorid AS author_id,
		(SELECT COUNT(*) FROM libavtor WHERE libavtor.avtorid=libavtorname.avtorid) cnt
		FROM libavtorname
		LEFT JOIN libapics USING(AvtorId)
		WHERE LOWER(libavtorname.lastname) LIKE :letter
		ORDER BY firstname LIMIT " . AUTHORS_PAGE . " OFFSET $start");
	$stmt->bindParam(":letter", $letter);
	$stmt->execute();
	$authors = $stmt->fetchAll(PDO::FETCH_OBJ);
	$count = $dbh->prepare('SELECT COUNT(*) FROM libavtorname WHERE LOWER(lastname) LIKE :letter');
	$count->execute([':letter' => $letter]);
	$cnt = (int)$count->fetchColumn();
}

echo '<div class="row">';
show_gpager(ceil($cnt / AUTHORS_PAGE), 5, $search_query === '' ? [] : ['q' => $search_query]);
foreach ($authors as $a) {
	if (($a->book_count ?? $a->cnt ?? 0) > 0) {
		echo "<div class='col col-sm-6 mb-3 d-flex justify-content-between'>";
		echo "<a class='mw-100 rounded-pill author' href='$webroot/author/view/$a->author_id'>";
		if (isset($a->file) && $a->file != '') {
			echo "<img class='rounded-circle contact' src='$webroot/extract_author.php?id=$a->author_id' />";	
		}
		echo "&nbsp;" . htmlspecialchars("$a->lastname $a->firstname $a->middlename $a->nickname") . "&nbsp;</a>";
		echo "<div class='badge bg-secondary'>" . ($a->book_count ?? $a->cnt) . "</div>";
		echo "</div>";

	}
}
echo "</div>";

show_gpager(ceil($cnt / AUTHORS_PAGE), 5, $search_query === '' ? [] : ['q' => $search_query]);
