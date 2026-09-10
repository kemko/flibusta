
<div class='row'>
<div class="col-sm-6">
<div class='card'>
<h4 class="rounded-top p-1" style="background: #d0d0d0;">Статистика</h4>
<div class='card-body'>
<?php
$status_import = (trim(shell_exec('ps aux|grep app_|grep -v grep') ?? '') !== '');

function get_ds($path){
	$io = popen ( '/usr/bin/du -sk ' . $path, 'r' );
	$size = fgets ( $io, 4096);
	$size = substr ( $size, 0, strpos ( $size, "\t" ) );
	pclose ( $io );
	return round((float)$size / 1024, 1);
}

if (!$status_import) {
	$cache_size = get_ds("/application/cache/covers") + get_ds("/application/cache/authors");
	$books_size = round(get_ds("/application/flibusta") / 1024, 1);
	$qtotal = $dbh->query("SELECT (SELECT MAX(time) FROM libbook) mmod, (SELECT COUNT(*) FROM libbook) bcnt, (SELECT COUNT(*) FROM libbook WHERE deleted='0') bdcnt");
	$qtotal->execute();
	$total = $qtotal->fetch();
	echo "<table class='table'><tbody>";
	echo "<tr><td>Актуальность базы:</td><td>$total->mmod</td></tr>";
	echo "<tr><td>Всего произведений:</td><td>$total->bcnt</td></tr>";
	echo "<tr><td>Размер архива:</td><td>$books_size Gb</td></tr>";
	echo "<tr><td>Размер кэша:</td><td>$cache_size Mb</td></tr>";
	echo "</tbody></table>";
} else {
	echo "Идёт процесс импорта...";
}
?>
</div>
</div>
</div>

<div class="col-sm-6">
<div class='card'>
<h4 class="rounded-top p-1" style="background: #d0d0d0;">Операции</h4>
<div class='card-body'>
<?php


if (isset($_POST['empty'])) {
	flibusta_auth_require_post_csrf();
	shell_exec('rm /application/cache/authors/*');
	shell_exec('rm /application/cache/covers/*');
	header("location:$webroot/service/");
}

if (!$status_import) {
	if (isset($_POST['import'])) {
		flibusta_auth_require_post_csrf();
		shell_exec('stdbuf -o0 /application/tools/app_import_sql.sh 2>/dev/null >/dev/null &');
		$status_fetch = true;
		header("location:$webroot/service/");
	}
	if (isset($_POST['reindex'])) {
		flibusta_auth_require_post_csrf();
		shell_exec('stdbuf -o0 /application/tools/app_reindex.sh 2>/dev/null >/dev/null &');
		$status_fetch = true;
		header("location:$webroot/service/");
	}
}

if ($status_import) {
	$status = 'disabled';
} else {
	$status = '';
}
echo "<div class='d-flex justify-content-between'>";
echo flibusta_auth_post_form($webroot . '/service/', ['import' => 'sql'], "btn btn-primary m-1 $status", 'Обновить базу') . ' ';
echo flibusta_auth_post_form($webroot . '/service/', ['empty' => 'cache'], 'btn btn-warning m-1', 'Очистить кэш') . ' ';
echo flibusta_auth_post_form($webroot . '/service/', ['reindex' => 'metadata'], 'btn btn-warning m-1', 'Сканировать ZIP и метаданные') . ' ';
echo "</div>";

if ($status_import) {
	$op = file_get_contents('/application/sql/status');;
	echo "<div class='d-flex align-items-center m-3'>";
	echo nl2br($op);
	echo "<div class='spinner-border ms-auto' role='status' aria-hidden='true'></div></div>";
	header("Refresh:10");
}

?>
</div>
</div>
</div>

</div>

<div class='row'>
<div class="col-sm-12 mt-3">
<div class='card'>
<div class='card-body'>
<p>
Для выполнения обновления необходимо разместить фалы дампа Флибусты (*.sql) в каталог FlibustaSQL. Процесс занимает до 30 минут, в зависимости от быстродействия сервера (SSD значительно увеличивает скорость импорта)
</p>
<p>
Чтобы отображались фото авторов и обложек для форматов, отличных от FB2, необходимо разместить в каталоге cache файлы архивов lib.a.attached.zip и lib.b.attached.zip соответственно.
В кэше хранятся распакованные фото авторов и обложек для FB2, а также их уменьшенные версии.</p>
<p>Файлы архивов Флибусты (*.zip) необходимо размещать в каталоге Flibusta.Net. Обрабатываются также файлы ежедневных обновлений, но обязательно необходимо подгружать свежие SQL файлы.</p>
<?php echo "<p>Доступен также OPDS-каталог для читалок: <a href='$webroot/opds/'>/opds/</a> (ключи: <a href='$webroot/opds_keys/'>управление</a>).</p>"; ?>
<p><b>Каталоги FlibustaSQL, cache и их подкаталоги должны иметь права на запись для контейнера. Скрипты в каталоге /application/tools/ должны иметь права на выполнение.</b></p>
</div></div></div></div>
