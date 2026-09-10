<script>
var isScrolling;

window.addEventListener('scroll', function ( event ) {
	window.clearTimeout( isScrolling );
	isScrolling = setTimeout(function() {
		console.log( this.scrollY );
		var x = new XMLHttpRequest();
		x.open("POST", "<?php echo "$webroot/save_position.php";?>", true);
		x.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
		x.send("csrf=<?php echo rawurlencode(flibusta_auth_csrf_token()); ?>&bookid=<?php echo (int)$url->var1; ?>&pos=" + encodeURIComponent(100 / document.body.scrollHeight * this.scrollY));
	}, 66);

}, false);
</script>


<?php

if ($user_uuid != "") {
	$stmt = $dbh->prepare("SELECT pos FROM progress WHERE user_uuid=:uuid AND bookid=:id LIMIT 1");
	$stmt->bindParam(":uuid", $user_uuid);
	$stmt->bindParam(":id", $url->var1);
	$stmt->execute();
	if ($p = $stmt->fetch()) {
		echo "<script>";
		echo 'document.addEventListener("DOMContentLoaded", function(event) {';
		echo "window.scrollTo(0, (document.body.scrollHeight / 100 *" . $p->pos . "));\n";
		echo "});\n";
		echo "</script>";
	}
}


$content = '';
$data = $book_data;

$fb2 = simplexml_load_string($data);
echo ($fb2 ? '' : 'FB2 Parse Error'), PHP_EOL;

$images = array();
foreach ($fb2->binary as $binary) {
	$id = $binary->attributes()['id'];
	$images["$id"] = $binary;
}

if (isset($fb2->body->section)) {
	foreach ($fb2->body->section as $section) {
		$s = $section->asXML();
		$s = str_replace("<title>", "<subtitle>", $s);
		$s = str_replace("</title>", "</subtitle>", $s);
		$s = str_replace('<image l:href="#', '<img style="width:100%;" src="', $s);
		foreach (array_keys($images) as $i) {
			$s = str_replace($i, "data:image/jpeg;base64," . $images[$i], $s);
		}
		$content .= $s;
	}
} else {
	$s = $fb2->body->asXML();
	$s = str_replace("<title>", "<subtitle>", $s);
	$s = str_replace("</title>", "</subtitle>", $s);
	$s = str_replace('<image l:href="#', '<img style="width:100%;" src="', $s);
	foreach (array_keys($images) as $i) {
		$s = str_replace($i, "data:image/jpeg;base64," . $images[$i], $s);
	}
	$content .= $s;
}
echo str_replace("<p>***</p>",  '<div class="divider div-transparent div-dot"></div>', str_replace("section>>", "section>", $content));
