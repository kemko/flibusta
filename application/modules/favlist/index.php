<?php 
echo '<div class="block rounded" style="margin-bottom:8px;"><form method="post" action="'.$webroot.'/">'; ?>
<div class="input-group mb-3">
	<input type="hidden" name="csrf" value="<?php echo htmlspecialchars(flibusta_auth_csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
   <input name="new_uuid" type="text" class="form-control" placeholder="Новая полка" aria-label="Новая полка" aria-describedby="basic-addon2">
   <div class="input-group-append">
   <input type="submit" class="btn btn-outline-secondary" value="Создать">
 </div>
</div>
</form>
</div>

<?php

echo '<div class="row">';
if ($user_uuid !== '') {
	$stmt = $dbh->prepare('SELECT * FROM fav_users WHERE user_uuid = :uuid');
	$stmt->execute([':uuid' => $user_uuid]);
	while ($a = $stmt->fetch()) {
	echo "<div class='col-sm-6'>";
	echo "<div class='card mb-3'>";

	echo "<div class='card-header'>";
	echo htmlspecialchars($a->name, ENT_QUOTES, 'UTF-8');
	echo "</div>";

	echo "<div class='card-body'>";
	$bs = $dbh->prepare("SELECT (SELECT COUNT(*) cnt FROM fav WHERE user_uuid=:uuid AND bookid is not null) bcnt, (SELECT COUNT(*) cnt FROM fav WHERE user_uuid=:uuid AND avtorid is not null) acnt, (SELECT COUNT(*) cnt FROM fav WHERE user_uuid=:uuid AND seqid is not null) scnt");
	$bs->bindParam(":uuid", $a->user_uuid);
	$bs->execute();
	$sta = $bs->fetch();
	echo '<ul class="list-group list-group-horizontal">';
	echo "<li class='list-group-item flex-fill'>Книг: $sta->bcnt</li>";
	echo "<li class='list-group-item flex-fill'>Авторов: $sta->acnt</li>";
	echo "<li class='list-group-item flex-fill'>Серий: $sta->scnt</li>";
	echo "</ul>";
	echo "</div>";

	echo "<div class='card-footer'>";
	echo flibusta_auth_post_form($webroot . '/', ['delete_uuid' => $a->user_uuid], 'btn btn-danger btn-sm float-end', 'Удалить');
	echo "</div>";

	echo "</div>";
	echo "</div>";
}
}
echo "</div>";
