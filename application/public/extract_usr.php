<?php
include('../init.php');
flibusta_auth_require_book_access($dbh);

if (!isset($_GET['id']) || filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
	http_response_code(404);
	die();
}
$id = (int)$_GET['id'];

$book = DBO::query("SELECT libbook.Title BookTitle, libbook.FileType, libfilename.filename,
	CONCAT(libavtorname.LastName, ' ', libavtorname.FirstName) author_name
		FROM libbook 
		LEFT JOIN libavtor USING(BookId) 
		LEFT JOIN libavtorname USING(AvtorId) 
		LEFT JOIN libfilename USING(BookId) 
		WHERE libbook.BookId=" . DBO::es($id))->fetchObject();

try {
	$file = book_file_find($dbh, $id);
	if ($file['format'] === 'fb2') {
		throw new BookFileException('Not a user-format book');
	}
	$filename = $book->author_name . " - " . $book->booktitle . " " . $book->filename . "." . trim($book->filetype);

	header('Content-Description: File Transfer');
	header('Content-Type: application/octet-stream');
	header('Content-Transfer-Encoding: binary');
	header('Expires: 0');
	header('Cache-Control: must-revalidate');
	header('Pragma: public');
	header('Content-Disposition: attachment; filename=' . basename(rawurlencode($filename)));

	echo book_file_contents($file);
} catch (BookFileException $error) {
	http_response_code(404);
	echo 'Book file is unavailable';
}
