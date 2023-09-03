<?php
/*
 *  -!- 2048.php -!-
 *
 *   Made of cursed hacks and elephant tears.
 *   Will not function on Windows systems due to the lack of Unix domain socket
 * support. WAMP is not real.
 *   Requires a web server that:
 *   - is capable of processing multiple requests in parallel.
 *   - returns the chunked response to the client as-is, without buffering.
 *
 *   Example lighttpd config (this script is located at /path/to/www/index.php):
 *
 *      server.document-root = "/path/to/www/"
 *      server.port = 4444
 *      server.stream-response-body = 2
 *      server.modules += ( "mod_cgi", "mod_rewrite" )
 *      url.rewrite-once = ( "^/(.*)" => "/" )
 *      cgi.assign = ( ".php" => "/usr/bin/php-cgi" )
 *      index-file.names = ( "index.php" )
 *
 */

include 'game.php';

ignore_user_abort(true);
define("DIR", getenv("C2K48_TMP_PREFIX") ?: "./tmp/c2k48_");

// the handler never runs in practice but not having one makes the script
// terminate on connection abort. i have no idea why.
$alive = true;
pcntl_signal(SIGTERM, function () {
	global $alive;
	$alive = false;
	error_log("bai!");
});

$chunking = false;

function chunk_start() {
	global $chunking;
	if ($chunking) {
		throw new Exception("already chunking");
	}

	$chunking = true;
	if (ob_get_level() == 0) {
		ob_start();
	}

	header("Content-type: text/html; charset=utf-8");
	header("X-Content-Type-Options: nosniff");
	header("Transfer-encoding: chunked");
}

function chunk($str) {
	global $chunking;
	if (!$chunking) {
		throw new Exception("not chunking");
	}

	printf("%s\r\n", dechex(strlen($str)));
	printf("%s\r\n", $str);
	ob_flush();
	flush();
	if ($str == '') {
		$chunking = false;
		ob_end_flush();
	}
}

function chunk_end() {
	chunk("");
}

function game(&$quitf) {
	global $alive;
	$uid = bin2hex(random_bytes(8));
	$dir = DIR . hash("sha256", $uid);
	$socket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
	socket_bind($socket, $dir);
	socket_set_nonblock($socket);
	$quitf = function () use (&$socket, &$dir) {
		socket_close($socket);
		unlink($dir);
	};
	chunk_start();
	$headch = <<<'Eof'
	<!DOCTYPE html>
	<head><title>2048.php</title></head>
	<style>
	#board{width:25em;height:25em;border:solid 1px black;order:1}
	#if{display:none}
	.conbc{width:6.25em;height:3em;display:inline-block}
	.conb{font-size:1em;width:100%%;height:100%%}
	form{display:none}
	.b{width:25%%;height:25%%;display:none}
	%s</style>
	<iframe id=if name=out></iframe>
	<div id=board>
	%s</div>
	<div id=con>
	%s
	</div>
	<style>

	Eof;
	$styles = "";
	$cons = "";
	$elems = "";
	foreach ([
		["l", "&lt;"],
		["d", "v"],
		["u", "^"],
		["r", "&gt;"],
	] as [$cmd, $label]) {
		$cons .=
			"<form method=post action=act/$uid/$cmd id=cb$cmd name=cb$cmd target=out></form>" .
			"<span class=conbc><button class=conb form=cb$cmd>$label</button></span>";
	}
	unset($cmd, $label);
	$bvalc = 16;
	$elems .= sprintf("<pre>%s</pre>", htmlspecialchars(var_export(new x1p11(), true)));
	for ($i = 0; $i < 16; $i++) {
		$elid = 'b' . dechex($i);
		$elem = "<div class=b id=$elid>";
		for ($bval = 0; $bval < 16; $bval++) {
			$bvald = 2 ** ($bval + 1);
			$elnc = 'n' . dechex($bval);
			$elnid = $elid . 'n' . $elnc;
			$elem .= "<div class=$elnc id=$elnid>$elnc</div>";
		}
		$elem .= '</div>';
	}
	unset($i, $elem, $elid, $bval, $bvald, $elnc, $elnid);
	$headch = sprintf($headch, $styles, $elems, $cons);
	unset($styles, $elems, $cons);
	chunk($headch);
	while (1) {
		chunk("//");
		if (connection_aborted() || !$alive) {
			error_log("bye!");
			return;
		}
		$buf = '';
		while (socket_recv($socket, $buf, 65536, 0) != false) {

			chunk($buf);
		}
		usleep(50_000);
	}
	chunk(<<<Eof
	</style>
	Eof);
	chunk_end();
};
$path = explode('/', $_SERVER["REQUEST_URI"]);
$path = array_values(array_filter($path, function ($e) {
	return $e != '';
}));
$spath = implode('/', $path);
if ($spath == '') {
	echo <<<EOF
	<!DOCTYPE html>
	<head><title>2048</title></head>
	<h1>2048.php</h1>
	<p>An implementation of the popular puzzle game <a href="https://en.wikipedia.org/wiki/2048_(video_game)">2048</a>.</p>
	<p>As it relies heavily on progressive HTML rendering and advanced CSS features (e.g. <code>display: none</code>) it might not work in older web browsers.</p>
	<p><a href="play">Start game</a></p>
	EOF;
} else if ($spath == "play") {
	$quitf = function () {};
	try {
		game($quitf);
	} catch (\Throwable $e) {
		error_log($e);
	} finally {
		$quitf();
	}
} else {
	if ($path[0] == "play") {
		array_shift($path);
	}
	if ($path[0] != "act") {
		echo "404\n";
		return;
	}
	array_shift($path);
	if (count($path) < 2) {
		echo "bad\n";
		return;
	}
	$dir = DIR . hash("sha256", $path[0]);
	$socket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
	socket_sendto($socket, $path[1], strlen($path[1]), 0, $dir);
}
