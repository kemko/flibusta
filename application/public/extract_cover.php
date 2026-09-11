<?php
include('../init.php');
flibusta_auth_require_book_access($dbh);
$cover = '';
$q = 75;
header('Cache-Control: public, max-age=86400');

function resizeCover($filename, $newwidth, $newheight){
	$i = imagecreatefromstring($filename);
	$width = imagesx($i);
       	$height = imagesy($i);
    if($width > $height && $newheight < $height){
        $newheight = (int)round($height / ($width / $newwidth));
    } else if ($width < $height && $newwidth < $width) {
        $newwidth = (int)round($width / ($height / $newheight));
    } else {
        $newwidth = (int)round($width);
        $newheight = (int)round($height);
    }
    $thumb = imagecreatetruecolor($newwidth, $newheight);
    imagecopyresized($thumb, $i, 0, 0, 0, 0, $newwidth, $newheight, $width, $height);
    return $thumb;
}

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

$small = isset($_GET['small']);

if (isset($_GET['id'])) {
	$id = $_GET['id'];
} elseif (isset($_GET['sid'])) {
	$id = $_GET['sid'];
	$small = true;
} else {
	http_response_code(404);
	die();
}
if (filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
	http_response_code(404);
	die();
}
$id = (int)$id;
$iid = $id;

header("Content-type: image/jpeg");

if ($small) {
	if (file_exists(ROOT_PATH . "cache/covers/$id-small.jpg")) {
		lastm(ROOT_PATH . "cache/covers/$id-small.jpg");
		die();
	}
} else {
	if (file_exists(ROOT_PATH . "cache/covers/$id.jpg")) {
		lastm(ROOT_PATH . "cache/covers/$id.jpg");
		die();
	}
}

$stmt = $dbh->prepare('SELECT file FROM libbpics WHERE BookId=:id');
$stmt->execute([':id' => $id]);
$f = $stmt->fetch();

if (isset($f->file)) {
	$zip = new ZipArchive(); 
	if ($zip->open(ROOT_PATH . "cache/lib.b.attached.zip") === true) {
		$f = $zip->getFromName($f->file);
		$zip->close();
		if ($f !== false && strlen($f) > 0) {
			file_put_contents(ROOT_PATH . "cache/covers/$id.jpg", $f);
			$thm = resizeCover($f, 300, 400);
			imagejpeg($thm, ROOT_PATH . "cache/covers/$id-small.jpg", 75);
			imagedestroy($thm);
			if ($small) {
				if (file_exists(ROOT_PATH . "cache/covers/$id-small.jpg")) {
					lastm(ROOT_PATH . "cache/covers/$id-small.jpg");
				die();
				}
			} else {
				echo $f;
				die();
			}
		}
	}
}


try {
	$metadata = book_metadata_for_file(book_file_find($dbh, $id));
	$cover = $metadata['cover_data'] ?? '';
} catch (BookFileException|BookMetadataException $error) {
	$cover = '';
}

if (strlen($cover) < 100) {
	$cover = file_get_contents('/application/none.jpg');
	echo $cover;
	die();
} else {
	file_put_contents(ROOT_PATH . "cache/covers/$iid.jpg", $cover);
	$thm = resizeCover($cover, 300, 400);
	imagejpeg($thm, ROOT_PATH . "cache/covers/$iid-small.jpg", 75);
	imagedestroy($thm);
}

if ($small) {
	if (file_exists(ROOT_PATH . "cache/covers/$iid-small.jpg")) {
		lastm(ROOT_PATH . "cache/covers/$iid-small.jpg");
		die();
	}
} else {
	echo $cover;
}
