<?php

require_once '../init.php';

flibusta_auth_require_web();
$owner_hash = flibusta_auth_owner();
if ($owner_hash === null) {
	http_response_code(403);
	exit('Authenticated owner is required');
}

if (isset($_GET['download'])) {
	$job = cart_job_for_owner($dbh, (string)$_GET['download'], $owner_hash);
	if ($job === null || $job['state'] !== 'ready' || !is_string($job['result_path']) || !is_file($job['result_path'])) {
		http_response_code(404);
		exit('Compilation is unavailable');
	}
	header('Content-Type: application/x-fictionbook+xml; charset=utf-8');
	header('Content-Disposition: attachment; filename="compilation-' . $job['job_id'] . '.fb2"');
	header('Content-Length: ' . filesize($job['result_path']));
	readfile($job['result_path']);
	exit;
}

flibusta_auth_require_post_csrf();
try {
	$config = flibusta_config();
	$action = (string)($_POST['cart_action'] ?? '');
	$bookid = (int)($_POST['bookid'] ?? 0);
	if ($action === 'add') {
		cart_add($bookid, $config['limits']['compilation_books']);
	} elseif ($action === 'remove') {
		cart_remove($bookid);
	} elseif ($action === 'move') {
		cart_move($bookid, (int)($_POST['direction'] ?? 0));
	} elseif ($action === 'create') {
		cart_create_job($dbh, $owner_hash, (string)($_POST['title'] ?? ''), cart_items(), (string)($_POST['request_token'] ?? ''), $config);
	} elseif ($action === 'send_mail') {
		$job_id = (string)($_POST['job_id'] ?? '');
		compilation_mail_create_request($dbh, $job_id, $owner_hash, (string)($_POST['request_token'] ?? ''), $config);
		compilation_mail_forget_request_token($job_id);
	} else {
		throw new CartException('Unknown cart action');
	}
} catch (Throwable $error) {
	$_SESSION['flibusta_cart_error'] = $error->getMessage();
}
header('Location: ' . $webroot . '/cart/', true, 303);
