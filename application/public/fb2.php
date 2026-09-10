<?php
if (!isset($_GET['id']) || filter_var($_GET['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
	http_response_code(404);
	die();
}
$id = (int)$_GET['id'];
error_reporting(E_ALL);
include('../init.php');
flibusta_auth_require_web();

$stmt = $dbh->prepare("SELECT libbook.Title BookTitle, 
	CONCAT(libavtorname.LastName, ' ', libavtorname.FirstName) author_name
		FROM libbook 
		LEFT JOIN libbannotations USING(BookId) 
		LEFT JOIN libgenre USING(BookId) 
		LEFT JOIN libgenrelist USING(GenreId) 
		LEFT JOIN libseq USING(BookId) 
		LEFT JOIN libavtor USING(BookId) 
		LEFT JOIN libavtorname USING(AvtorId) 
		LEFT JOIN libseqname USING(SeqId) WHERE libbook.BookId=:id");
$stmt->bindParam(":id", $id);
$stmt->execute();
$book = $stmt->fetch();


try {
	$file = book_file_find($dbh, $id);
	if ($file['format'] !== 'fb2') {
		throw new BookFileException('Not an FB2 book');
	}
	$data = book_file_contents($file);
	$filename = $book->author_name . " - " . $book->booktitle . " " . $id . ".fb2";
	header('Content-Description: File Transfer');
	header('Content-Type: application/octet-stream');
	header('Content-Disposition: attachment; filename=' . basename(rawurlencode($filename)));
	header('Content-Transfer-Encoding: binary');
	header('Expires: 0');
	header('Cache-Control: must-revalidate');
	header('Pragma: public');
	echo $data;
} catch (BookFileException $error) {
	http_response_code(404);
	echo 'Book file is unavailable';
}

