<?php
// Real entry points and CLI processes against a disposable database.
require_once __DIR__ . '/bootstrap.php';

function check(bool $condition, string $message): void {
	if (!$condition) {
		throw new RuntimeException($message);
	}
}
function run_command(array $command, array $environment, ?string $cwd = null): string {
	$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $environment);
	check(is_resource($process), 'Cannot start test command');
	$output = stream_get_contents($pipes[1]);
	fclose($pipes[1]);
	check(proc_close($process) === 0, $output);
	return $output;
}
function web_user_command(array $command): array {
	return ['su', '-s', '/bin/sh', 'www-data', '-c', implode(' ', array_map('escapeshellarg', $command))];
}
function request(string $base, string $path, array $headers = [], ?array $post = null): array {
	$curl = curl_init($base . $path);
	curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 5]);
	if ($post !== null) {
		curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
	}
	$response = curl_exec($curl);
	check(is_string($response), curl_error($curl));
	$status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
	$header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
	curl_close($curl);
	return [$status, substr($response, $header_size), substr($response, 0, $header_size)];
}

$env = getenv();
$admin = new PDO('pgsql:host=' . $env['FLIBUSTA_DBHOST'] . ';dbname=' . $env['FLIBUSTA_DBNAME'], $env['FLIBUSTA_DBUSER'], $env['FLIBUSTA_DBPASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$database = 'http_' . bin2hex(random_bytes(6));
$directory = sys_get_temp_dir() . '/' . $database;
mkdir($directory, 0700);
chown($directory, 82);
chgrp($directory, 82);
$server = null;
$admin->exec('CREATE DATABASE ' . $database);
try {
	$env['FLIBUSTA_DBNAME'] = $database;
	$env['FLIBUSTA_BOOKS_DIR'] = $directory;
	$env['FLIBUSTA_CACHE_DIR'] = $directory;
	$env['FLIBUSTA_COMPILATIONS_DIR'] = $directory . '/results';
	$env['FLIBUSTA_TMP_DIR'] = $directory . '/tmp';
	$env['FLIBUSTA_PUBLIC_URL'] = 'https://library.example';
	$env['FLIBUSTA_OIDC_ISSUER'] = 'https://issuer.example';
	$env['FLIBUSTA_OIDC_CLIENT_ID'] = 'http-test';
	$env['FLIBUSTA_OPDS_OWNER_HMAC_KEY'] = 'test-only-owner-key';
	$dbh = new PDO('pgsql:host=' . $env['FLIBUSTA_DBHOST'] . ';dbname=' . $database, $env['FLIBUSTA_DBUSER'], $env['FLIBUSTA_DBPASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
	$schema = str_replace('OWNER TO flibusta;', 'OWNER TO ' . $env['FLIBUSTA_DBUSER'] . ';', file_get_contents('/project/init_db.sql'));
	$dbh->exec($schema);
	$dbh->exec('SET search_path TO public');
	app_migrate($dbh, dirname(__DIR__) . '/tools/migrations');
	$dbh->exec("INSERT INTO libbook (bookid, title, title1, filetype, keywords, md5, fileauthor) VALUES (10, 'HTTP book', '', 'fb2', '', '', ''); INSERT INTO libavtorname (avtorid, lastname, firstname, email, homepage) VALUES (1, 'Writer', 'Test', '', ''); INSERT INTO libavtor (bookid, avtorid) VALUES (10, 1)");
	$dbh->exec("INSERT INTO libseqname VALUES (1, 'HTTP series'); INSERT INTO libseq (bookid, seqid, seqnumb) VALUES (10, 1, 2)");
	$zip = new ZipArchive();
	$zip->open($directory . '/f.fb2-1-20.zip', ZipArchive::CREATE);
	$book = file_get_contents(__DIR__ . '/fixtures/books/compilation-one.fb2');
	$zip->addFromString('10.fb2', $book);
	$zip->close();
	foreach (['app_migrate.php', 'app_update_zip_list.php', 'app_scan_books.php', 'app_worker.php'] as $script) {
		$output = run_command(web_user_command([PHP_BINARY, dirname(__DIR__) . '/tools/' . $script]), $env, '/tmp');
		check(!str_contains($output, 'Fatal error') && !str_contains($output, 'Warning'), $output);
	}
	check((int)$dbh->query("SELECT count(*) FROM book_archive_entries WHERE scan_state = 'complete'")->fetchColumn() === 1, 'Standalone worker did not index the source');
	author_search_rebuild($dbh);
	$key = flibusta_opds_create_key($dbh, 'http-owner');
	$basic = ['Authorization: Basic ' . base64_encode($key['key_id'] . ':' . $key['secret'])];
	$login_script = $directory . '/session.php';
	file_put_contents($login_script, '<?php require "/application/config.php"; require "/application/auth.php"; $settings = flibusta_auth_settings(); flibusta_auth_session_start($settings); $old = session_id(); flibusta_auth_complete_login((object)["iss" => $settings["issuer"], "aud" => [$settings["client_id"]], "sub" => "test-subject", "exp" => time() + 600], $settings); if ($old === session_id()) { exit(1); } echo json_encode([session_id(), flibusta_auth_csrf_token()]); session_write_close();');
	[$session, $csrf] = json_decode(run_command(web_user_command([PHP_BINARY, '-d', 'session.save_path=' . $directory, $login_script]), $env), true, 512, JSON_THROW_ON_ERROR);
	$cookie = ['Cookie: flibusta_session=' . $session];
	$router = $directory . '/router.php';
	file_put_contents($router, '<?php chdir("/application/public"); $path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); if (is_file("/application/public" . $path)) { return false; } require "/application/public/index.php";');
	$socket = stream_socket_server('tcp://127.0.0.1:0');
	$address = stream_socket_get_name($socket, false);
	fclose($socket);
	$server = proc_open(web_user_command([PHP_BINARY, '-d', 'session.save_path=' . $directory, '-S', $address, '-t', '/application/public', $router]), [1 => ['file', $directory . '/http.log', 'a'], 2 => ['redirect', 1]], $pipes, '/application/public', $env);
	check(is_resource($server), 'Cannot start HTTP server');
	for ($attempt = 0; $attempt < 100; $attempt++) {
		$probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
		if ($probe) { fclose($probe); break; }
		usleep(10000);
	}
	$base = 'http://' . $address;
	foreach (['/', '/cart/', '/service/', '/opds_keys/'] as $path) {
		check(request($base, $path)[0] === 302, 'Anonymous HTML access: ' . $path);
		check(request($base, $path, $basic)[0] === 302, 'OPDS key opened HTML: ' . $path);
	}
	foreach (['/opds/', '/opds/search?by=author&q=Writer', '/fb2.php?id=10', '/extract_cover.php?id=10'] as $path) {
		[$status, , $headers] = request($base, $path);
		check($status === 401 && str_contains($headers, 'WWW-Authenticate: Basic'), 'Missing Basic challenge: ' . $path);
	}
	check(request($base, '/opds/', $basic)[0] === 200, 'OPDS catalog failed');
	[$status, $series] = request($base, '/opds/list?seq_id=1', $basic);
	check($status === 200 && str_contains($series, 'HTTP book'), 'OPDS series feed failed');
	[$status, $search] = request($base, '/opds/search?by=author&q=Writer', $basic);
	check($status === 200 && str_contains($search, 'Writer'), 'OPDS search failed');
	[$status, $alphabet] = request($base, '/opds/authorsindex', $basic);
	check($status === 200 && str_contains($alphabet, 'prefix=1&amp;q=W'), 'OPDS alphabet lacks prefix links');
	[$status, $authors] = request($base, '/opds/search?by=author&prefix=1&q=W', $basic);
	check($status === 200 && str_contains($authors, 'Writer Test'), 'OPDS alphabetical prefix lost the author');
	check(request($base, '/fb2.php?id=10', $basic)[1] === $book, 'OPDS download bytes differ');
	check(request($base, '/cart/', $cookie)[0] === 200, 'Authenticated cart failed');
	[$status, $card] = request($base, '/book/view/10', $cookie);
	check($status === 200 && str_contains($card, 'name="cart_action"'), 'Book card has no cart button without a shelf: ' . $status . ' ' . substr($card, -2000));
	[$status, $service] = request($base, '/service/', $cookie);
	check($status === 200 && substr_count($service, 'action="/service/"') === 3, 'Service forms do not target their handlers: ' . $status . ' ' . substr($service, -1200));
	check(request($base, '/service/', $cookie, ['empty' => 'cache'])[0] === 403, 'Service POST lacks CSRF gate');
	[$status, $search] = request($base, '/?q=' . rawurlencode('<script>alert(1)</script>'), $cookie);
	check($status === 200 && !str_contains($search, '<script>alert(1)</script>') && str_contains($search, '&lt;script&gt;'), 'Search query is not HTML escaped');
	foreach ([[], ['csrf' => 'wrong']] as $fields) {
		check(request($base, '/compilation.php', $cookie, $fields + ['cart_action' => 'add', 'bookid' => 10])[0] === 403, 'Invalid CSRF accepted');
	}
	check(str_contains(request($base, '/cart/', $cookie)[1], 'Добавьте книги'), 'Rejected request mutated the cart');
	check(request($base, '/compilation.php', $cookie, ['csrf' => $csrf, 'cart_action' => 'add', 'bookid' => 10])[0] === 303, 'Valid cart POST failed');
	check(str_contains(request($base, '/cart/', $cookie)[1], 'HTTP book'), 'Valid POST did not add book');
	$token = bin2hex(random_bytes(24));
	check(request($base, '/compilation.php', $cookie, ['csrf' => $csrf, 'cart_action' => 'create', 'title' => 'HTTP compilation', 'request_token' => $token])[0] === 303, 'Compilation POST failed');
	$job = $dbh->query('SELECT job_id FROM compilation_jobs')->fetchColumn();
	check(is_string($job), 'Compilation request created no job');
	run_command(web_user_command([PHP_BINARY, '/application/tools/app_worker.php']), $env);
	run_command(web_user_command([PHP_BINARY, '/application/tools/app_compilation_process.php', $directory . '/compilations/work/' . $job]), $env);
	run_command(web_user_command([PHP_BINARY, '/application/tools/app_worker.php']), $env);
	[$status, $compiled] = request($base, '/compilation.php?download=' . $job, $cookie);
	check($status === 200 && str_contains($compiled, 'HTTP compilation'), 'PHP-FPM user cannot download generated compilation');
	check(request($base, '/compilation.php', $cookie, ['csrf' => $csrf, 'cart_action' => 'send_mail', 'job_id' => $job, 'request_token' => bin2hex(random_bytes(24))])[0] === 303, 'Mail POST failed');
	run_command(web_user_command([PHP_BINARY, '/application/tools/app_worker.php']), $env);
	check($dbh->query('SELECT state FROM compilation_mail_requests')->fetchColumn() === 'accepted', 'PHP-FPM user could not request SMTP for generated compilation');
	$original_shelf = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
	$dbh->exec("INSERT INTO fav_users VALUES ('$original_shelf', 'Shared shelf'); INSERT INTO fav (user_uuid, bookid) VALUES ('$original_shelf', 10)");
	check(str_contains(request($base, '/favlist/', $cookie)[1], $original_shelf), 'Existing shelf is hidden after login');
	check(request($base, '/', $cookie, ['login_uuid' => $original_shelf])[0] === 403, 'Shelf selection lacks CSRF gate');
	request($base, '/', $cookie, ['csrf' => $csrf, 'new_uuid' => 'Shared shelf']);
	$new_shelf = $dbh->query("SELECT user_uuid FROM fav_users WHERE user_uuid <> '$original_shelf'")->fetchColumn();
	check(is_string($new_shelf), 'Duplicate shelf name did not create a separate shelf');
	check(request($base, '/', $cookie, ['csrf' => $csrf, 'delete_uuid' => $new_shelf])[0] === 200, 'Duplicate name selected the older shelf');
	check((int)$dbh->query("SELECT count(*) FROM fav WHERE user_uuid = '$original_shelf'")->fetchColumn() === 1, 'Deleting new shelf changed original favorites');
	request($base, '/', $cookie, ['csrf' => $csrf, 'login_uuid' => $original_shelf]);
	check(str_contains(request($base, '/favlist/', $cookie)[1], 'name="delete_uuid" value="' . $original_shelf . '"'), 'Cannot select existing shelf after fresh login');
	flibusta_opds_revoke_key($dbh, $key['key_id'], 'http-owner');
	foreach (['/opds/', '/opds/search?by=author&q=Writer', '/fb2.php?id=10'] as $path) {
		check(request($base, $path, $basic)[0] === 401, 'Revoked key accepted: ' . $path);
	}
	echo "HTTP access, OPDS series, shelves, CSRF, UID 82 compilation download/SMTP and standalone CLI checks passed.\n";
} finally {
	if (is_resource($server)) { proc_terminate($server); proc_close($server); }
	$dbh = null;
	$admin->exec('DROP DATABASE ' . $database . ' WITH (FORCE)');
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
		$file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
	}
	rmdir($directory);
}
