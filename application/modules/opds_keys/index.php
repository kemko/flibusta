<?php

$owner_hash = flibusta_auth_owner();
if ($owner_hash === null) {
	http_response_code(403);
	exit('Authenticated owner is required');
}

$new_key = null;
if (isset($_POST['create_opds_key'])) {
	flibusta_auth_require_post_csrf();
	$new_key = flibusta_opds_create_key($dbh, $owner_hash);
}
if (isset($_POST['revoke_opds_key'])) {
	flibusta_auth_require_post_csrf();
	flibusta_opds_revoke_key($dbh, (string)$_POST['revoke_opds_key'], $owner_hash);
}

echo '<h3>Ключи OPDS</h3>';
if ($new_key !== null) {
	echo '<p>Секрет показывается один раз. Логин: <code>' . htmlspecialchars($new_key['key_id'], ENT_QUOTES, 'UTF-8') . '</code><br>Пароль: <code>' . htmlspecialchars($new_key['secret'], ENT_QUOTES, 'UTF-8') . '</code></p>';
}
echo flibusta_auth_post_form($webroot . '/opds_keys/', ['create_opds_key' => '1'], 'btn btn-primary', 'Создать ключ');
echo '<table class="table"><thead><tr><th>Идентификатор</th><th>Создан</th><th>Последнее использование</th><th>Статус</th><th></th></tr></thead><tbody>';
foreach (flibusta_opds_keys($dbh, $owner_hash) as $key) {
	$id = htmlspecialchars($key['key_id'], ENT_QUOTES, 'UTF-8');
	echo '<tr><td><code>' . $id . '</code></td><td>' . htmlspecialchars((string)$key['created_at'], ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string)($key['last_used_at'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td>' . ($key['revoked_at'] === null ? 'активен' : 'отозван') . '</td><td>';
	if ($key['revoked_at'] === null) {
		echo flibusta_auth_post_form($webroot . '/opds_keys/', ['revoke_opds_key' => $key['key_id']], 'btn btn-warning btn-sm', 'Отозвать');
	}
	echo '</td></tr>';
}
echo '</tbody></table>';
