<?php
include('../init.php');
flibusta_auth_require_web();
flibusta_auth_require_post_csrf();

$user_uuid = $_SESSION['user_uuid'] ?? '';
if ($user_uuid === '') {
	http_response_code(409);
	exit('No shelf selected');
}
$bookid = intval($_POST['bookid'] ?? 0);
$pos = floatval($_POST['pos'] ?? 0);

if ($pos == 0) {
	$stmt = $dbh->prepare("DELETE FROM progress WHERE user_uuid=:uuid AND bookid=:id");
	$stmt->bindParam(":uuid", $user_uuid);
	$stmt->bindParam(":id", $bookid);
	$stmt->execute();
	die();
}

$stmt = $dbh->prepare("INSERT INTO progress (user_uuid, bookid, pos) VALUES (:uuid, :id, :pos) ON CONFLICT(user_uuid, bookid) DO UPDATE set pos=:pos2");
$stmt->bindParam(":uuid", $user_uuid);
$stmt->bindParam(":id", $bookid);
$stmt->bindParam(":pos", $pos);
$stmt->bindParam(":pos2", $pos);
$stmt->execute();
