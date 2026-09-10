<?php
ob_start();

include("../init.php");
decode_gurl($webroot);

if ($url->mod === 'opds') {
	flibusta_opds_require($dbh);
} else {
	flibusta_auth_require_web();
}

$user_name = 'Книжные полки';
if (isset($_POST['delete_uuid'])) {
	flibusta_auth_require_post_csrf();
	$uu = $_SESSION['user_uuid'] ?? '';
	if ($uu === '' || !hash_equals($uu, (string)$_POST['delete_uuid'])) {
		http_response_code(403);
		exit('Shelf is not owned by this session');
	}
	$stmt = $dbh->prepare("DELETE FROM fav_users WHERE user_uuid=:uuid");
	$stmt->bindParam(":uuid", $uu);
	$stmt->execute();
	$st = $dbh->prepare("DELETE FROM fav WHERE user_uuid=:uuid");
	$st->bindParam(":uuid", $uu);
	$st->execute();
	unset($_SESSION['user_uuid']);
}

if (isset($_POST['new_uuid'])) {
	flibusta_auth_require_post_csrf();
	$nname = trim((string)$_POST['new_uuid']);
	if ($nname !== '') {
		$stmt = $dbh->prepare("INSERT INTO fav_users (user_uuid, name) VALUES (uuid_generate_v1(), :name)");
		$stmt->bindParam(":name", $nname);
		$stmt->execute();

		$stmt = $dbh->prepare("SELECT user_uuid FROM fav_users WHERE name=:name LIMIT 1");
		$stmt->bindParam(":name", $nname);
		$stmt->execute();
		$r = $stmt->fetch();
		$user_uuid = $r->user_uuid;
		$user_name = $nname;
		$_SESSION['user_uuid'] = $user_uuid;
	}
}

if (isset($_SESSION['user_uuid'])) {
	$user_uuid = $_SESSION['user_uuid'];
	$stmt = $dbh->prepare("SELECT * FROM fav_users WHERE user_uuid=:uuid");
	$stmt->bindParam(":uuid", $user_uuid);
	try {
		$stmt->execute();
		$user = $stmt->fetch();
	} catch (PDOException $e) {
		//
	}
	
	if (isset($user->name)) {
		$user_name = $user->name;

		if (isset($_POST['fav_book'])) {
			flibusta_auth_require_post_csrf();
			$id = intval($_POST['fav_book']);
			$st = $dbh->prepare("INSERT INTO fav (user_uuid, bookid) VALUES(:uuid, :id) ON CONFLICT DO NOTHING");
			$st->bindParam(":uuid", $user_uuid);
			$st->bindParam(":id", $id);
			$st->execute();
		}
		if (isset($_POST['fav_author'])) {
			flibusta_auth_require_post_csrf();
			$id = intval($_POST['fav_author']);
			$st = $dbh->prepare("INSERT INTO fav (user_uuid, avtorid) VALUES(:uuid, :id) ON CONFLICT DO NOTHING");
			$st->bindParam(":uuid", $user_uuid);
			$st->bindParam(":id", $id);
			$st->execute();
		}
		if (isset($_POST['fav_seq'])) {
			flibusta_auth_require_post_csrf();
			$id = intval($_POST['fav_seq']);
			$st = $dbh->prepare("DELETE FROM fav WHERE user_uuid=:uuid AND seqid=:id");
			$st->bindParam(":uuid", $user_uuid);
			$st->bindParam(":id", $id);
			$st->execute();
			$st = $dbh->prepare("INSERT INTO fav (user_uuid, seqid) VALUES(:uuid, :id) ON CONFLICT DO NOTHING");
			$st->bindParam(":uuid", $user_uuid);
			$st->bindParam(":id", $id);
			$st->execute();
		}
	
		if (isset($_POST['unfav_book'])) {
			flibusta_auth_require_post_csrf();
			$id = intval($_POST['unfav_book']);
			$st = $dbh->prepare("DELETE FROM fav WHERE user_uuid=:uuid AND bookid=:id");
			$st->bindParam(":uuid", $user_uuid);
			$st->bindParam(":id", $id);
			$st->execute();
		}
		if (isset($_POST['unfav_author'])) {
			flibusta_auth_require_post_csrf();
			$id = intval($_POST['unfav_author']);
			$st = $dbh->prepare("DELETE FROM fav WHERE user_uuid=:uuid AND avtorid=:id");
			$st->bindParam(":uuid", $user_uuid);
			$st->bindParam(":id", $id);
			$st->execute();
		}
		if (isset($_POST['unfav_seq'])) {
			flibusta_auth_require_post_csrf();
			$id = intval($_POST['unfav_seq']);
			$st = $dbh->prepare("DELETE FROM fav WHERE user_uuid=:uuid AND seqid=:id");
			$st->bindParam(":uuid", $user_uuid);
			$st->bindParam(":id", $id);
			$st->execute();
		}
	} else {
		unset($_SESSION['user_uuid']);
		$user_name = 'Книжные полки';
	}
} else {
	$user_uuid = '';
}

if (isset($_GET['sort'])) {
	$sort_mode = $_GET['sort'];
} else {
	$sort_mode = 'abc';
	if ($url->action == '') {
		$sort_mode = 'date';
	}
}

if (isset($_GET['page'])) {
	$page = intval($_GET['page']);
} else {
	$page = 0;
}

$start = $page * RECORDS_PAGE;
$lang = 'ru';
$filter = "";

switch ($sort_mode) {
	case 'abc':
		$order = 'b.Title';
		break;

	case 'author':
		$order = 'b.Title';
		break;

	case 'date':
		$order = 'b.Time DESC';
		break;

	case 'rating':
		$order = 'b.Title';
		break;
}

if ($url->mod == 'opds') {
	include(ROOT_PATH . "/opds/index.php");
} else {
	include(ROOT_PATH . "renderer.php");
}
