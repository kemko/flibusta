<?php
include('../init.php');
flibusta_auth_require_book_access($dbh);
$cover = '';
header('Cache-Control: public, max-age=86400');

function lastm($path) {
	$fmtimestamp = filemtime($path);
	if(isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $fmtimestamp <= strtotime($_SERVER['HTTP_IF_MODIFIED_SINCE'])) {
		header($_SERVER['SERVER_PROTOCOL'] . ' 304 Not Modified');
		die();
	} else {
		header("Expires: " . gmdate("D, d M Y H:i:s", filemtime($path) + 60*60*24) . " GMT");
		header("Last-Modified: " . gmdate("D, d M Y H:i:s", filemtime($path)) . " GMT");

		echo file_get_contents($path);
	}
}

$id = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($id === false) {
	http_response_code(400);
	exit('Invalid author ID');
}

header("Content-type: image/jpeg");

if (file_exists(ROOT_PATH . "cache/authors/$id.jpg")) {
	lastm(ROOT_PATH . "cache/authors/$id.jpg");
	die();
}

$stmt = $dbh->prepare('SELECT file FROM libapics WHERE AvtorId = :id');
$stmt->execute([':id' => $id]);
$f = $stmt->fetch();

if (isset($f->file)) {
	$zip = new ZipArchive(); 
	if ($zip->open(ROOT_PATH . "cache/lib.a.attached.zip") === true) {
		$f = $zip->getFromName($f->file);
		$zip->close();
		if ($f !== false && strlen($f) > 0) {
			file_put_contents(ROOT_PATH . "cache/authors/$id.jpg", $f);
			echo $f;
			die();
		}
	}
}
