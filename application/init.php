<?php
define('ROOT_PATH', '/application/');
define('RECORDS_PAGE', 10);
define('BOOKS_PAGE', 10);
define('AUTHORS_PAGE', 50);
define('SERIES_PAGE', 50);
define('OPDS_FEED_COUNT', 100);
define('COUNT_BOOKS', true);
require_once ROOT_PATH . 'config.php';
require_once ROOT_PATH . 'book_files.php';
require_once ROOT_PATH . 'book_metadata.php';
require_once ROOT_PATH . 'compilation.php';
require_once ROOT_PATH . 'cart.php';
require_once ROOT_PATH . 'author_search.php';
require_once ROOT_PATH . 'auth.php';
require_once ROOT_PATH . 'opds_auth.php';
if (is_file('/opt/flibusta-vendor/autoload.php')) {
	require_once '/opt/flibusta-vendor/autoload.php';
}
include(ROOT_PATH . 'functions.php');
include(ROOT_PATH . 'dbinit.php');
include_once(ROOT_PATH . 'webroot.php');

error_reporting(E_ALL);

$cdt = date('Y-m-d H:i:s');
