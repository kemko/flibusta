<?php

$error = $_SESSION['flibusta_cart_error'] ?? null;
unset($_SESSION['flibusta_cart_error']);
$items = cart_items();
$books = [];
if ($items !== []) {
	$placeholders = implode(', ', array_fill(0, count($items), '?'));
	$statement = $dbh->prepare("SELECT bookid, title, filetype FROM libbook WHERE bookid IN ({$placeholders})");
	$statement->execute($items);
	foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $book) {
		$books[(int)$book['bookid']] = $book;
	}
}
$owner_hash = flibusta_auth_owner();
$jobs = [];
if ($owner_hash !== null) {
	$statement = $dbh->prepare('SELECT job_id, title, state, error, created_at FROM compilation_jobs WHERE owner_hash = :owner_hash ORDER BY created_at DESC LIMIT 20');
	$statement->execute([':owner_hash' => $owner_hash]);
	$jobs = $statement->fetchAll(PDO::FETCH_ASSOC);
	$mail_requests = compilation_mail_requests_for_owner($dbh, $owner_hash);
}
$state_names = ['queued' => 'ожидает', 'processing' => 'выполняется', 'ready' => 'готов', 'error' => 'ошибка'];
$mail_state_names = ['queued' => 'ожидает отправки', 'processing' => 'отправляется', 'accepted' => 'принято SMTP-сервером (доставка не подтверждена)', 'unknown' => 'результат SMTP неизвестен'];
$mail_by_job = [];
foreach ($mail_requests ?? [] as $request) {
	$mail_by_job[$request['job_id']] ??= $request;
}

echo '<h3>Корзина сборника</h3>';
if (is_string($error)) {
	echo '<div class="alert alert-warning">' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</div>';
}
if ($items === []) {
	echo '<p>Добавьте книги из списка или карточки.</p>';
} else {
	echo '<ol class="list-group list-group-numbered mb-3">';
	foreach ($items as $position => $bookid) {
		$book = $books[$bookid] ?? ['title' => 'Недоступная книга', 'filetype' => ''];
		echo '<li class="list-group-item d-flex justify-content-between align-items-center"><span>' . htmlspecialchars((string)$book['title'], ENT_QUOTES, 'UTF-8') . ' <small>' . htmlspecialchars((string)$book['filetype'], ENT_QUOTES, 'UTF-8') . '</small></span><span class="btn-group">';
		if ($position > 0) {
			echo flibusta_auth_post_form($webroot . '/compilation.php', ['cart_action' => 'move', 'bookid' => $bookid, 'direction' => -1], 'btn btn-outline-secondary btn-sm', '↑');
		}
		if ($position < count($items) - 1) {
			echo flibusta_auth_post_form($webroot . '/compilation.php', ['cart_action' => 'move', 'bookid' => $bookid, 'direction' => 1], 'btn btn-outline-secondary btn-sm', '↓');
		}
		echo flibusta_auth_post_form($webroot . '/compilation.php', ['cart_action' => 'remove', 'bookid' => $bookid], 'btn btn-outline-danger btn-sm', 'Удалить');
		echo '</span></li>';
	}
	echo '</ol>';
	echo '<form method="post" action="' . htmlspecialchars($webroot . '/compilation.php', ENT_QUOTES, 'UTF-8') . '">';
	echo '<input type="hidden" name="csrf" value="' . htmlspecialchars(flibusta_auth_csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
	echo '<input type="hidden" name="cart_action" value="create"><input type="hidden" name="request_token" value="' . htmlspecialchars(cart_request_token(), ENT_QUOTES, 'UTF-8') . '">';
	echo '<label class="form-label" for="compilation-title">Название сборника</label><input required maxlength="256" class="form-control mb-2" id="compilation-title" name="title">';
	echo '<button class="btn btn-primary" type="submit">Создать сборник FB2</button></form>';
}
if ($jobs !== []) {
	echo '<h4 class="mt-4">Задания</h4><table class="table"><thead><tr><th>Название</th><th>Состояние</th><th></th></tr></thead><tbody>';
	foreach ($jobs as $job) {
		$state = $state_names[$job['state']] ?? $job['state'];
		echo '<tr><td>' . htmlspecialchars($job['title'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars($state . ($job['error'] ? ': ' . $job['error'] : ''), ENT_QUOTES, 'UTF-8') . '</td><td>';
		if ($job['state'] === 'ready') {
			echo '<a class="btn btn-success btn-sm" href="' . htmlspecialchars($webroot . '/compilation.php?download=' . rawurlencode($job['job_id']), ENT_QUOTES, 'UTF-8') . '">Скачать FB2</a>';
			$mail = $mail_by_job[$job['job_id']] ?? null;
			if ($mail !== null) {
				echo '<small class="d-block mt-1">Почта: ' . htmlspecialchars(($mail_state_names[$mail['state']] ?? $mail['state']) . ($mail['error'] ? ': ' . $mail['error'] : ''), ENT_QUOTES, 'UTF-8') . '</small>';
			}
			if ($mail === null || $mail['state'] === 'unknown') {
				$token = compilation_mail_request_token((string)$job['job_id'], $mail !== null);
				echo flibusta_auth_post_form($webroot . '/compilation.php', ['cart_action' => 'send_mail', 'job_id' => $job['job_id'], 'request_token' => $token], 'btn btn-outline-primary btn-sm', $mail === null ? 'Отправить по почте' : 'Повторить отправку');
			}
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';
}
