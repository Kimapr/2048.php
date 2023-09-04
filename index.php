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
include 'webutil.php';

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
	$styles = "";
	$cons = "";
	$elems = "";
	$statusl = "";
	$appendf = function (&$s) {return function ($a) use (&$s) {$s .= $a;};};
	$callf = function(&$f){return function(...$a)use(&$f){return $f(...$a);};};
	$stwrite = $appendf($styles);
	$stylist = new StyleMutator($callf($stwrite));
	foreach ([
		["l", "&lt;",1,2],
		["d", "v",2,3],
		["u", "^",2,1],
		["r", "&gt;",3,2],
	] as [$cmd, $label,$x,$y]) {
		$cons .=
			"<form method=post action=act/$uid/$cmd id=cb$cmd name=cb$cmd target=out></form>" .
			"<div class=conbc id=cbd$cmd><button class=conb form=cb$cmd>$label</button></div>";
		$stylist->set("#cbd$cmd","grid-row-start",$y);
		$stylist->set("#cbd$cmd","grid-column-start",$x);
	}
	$statusl .= "<div>";
	$tlab = new CursedNumber($stylist, "tcc", $appendf($statusl));
	$statusl .=" fps</div><div>score: ";
	$scor = new CursedNumber($stylist, "scc", $appendf($statusl));
	$tlab->draw(0);
	$scor->draw(0);
	$game = new x1p11();
	[$w,$h]=$game->dimensions();
	$statusl .= "</div>";
	unset($cmd, $label);
	$bvalc = 16;
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
	$stylist->present();
	$headch = <<<Eof
	<!DOCTYPE html>
	<head><meta name="viewport" content="width=device-width" /><title>2048.php</title></head>
	<style>
	body,html{width:100%;height:100%;margin:0;padding:0;font-size:min(1em,3vmin)}
	#cont{display:flex;width:100%;height:100%;flex-direction:row;}
	#bcont{flex-grow:1;padding:2em;display:flex;align-items:center;justify-content:center}
	.filler{flex-grow:1}
	#sidec{font-size:1.5em;flex-basis:min(13rem,50vmin);padding:1rem;border-left:solid 1px black;display:flex;flex-direction:column;}
	@media(max-aspect-ratio:1/1){
		#cont{flex-direction:column}
		#sidec{flex-direction:row;border-left:initial;border-top:solid 1px black}
	}
	#bcont2{width:100%;max-width:100%;max-height:100%;aspect-ratio:1;display:flex;justify-content:center;align-items:center}
	#board{height:100%;max-width:100%;aspect-ratio:$w/$h;border:solid 1px black;order:1}
	#if{display:none}
	#con{display:grid;aspect-ratio:1;}
	.conbc{width:100%;height:100%;}
	.conb{font-size:1em;width:100%;height:100%}
	form{display:none}
	.b{width:25%;height:25%;display:none}
	</style>
	$styles<iframe id=if name=out></iframe>
	<div id=cont>
	<div id=bcont><div id=bcont2><div id=board>
	$elems</div></div></div>
	<div id=sidec>
	<div id=status>$statusl</div>
	<div class=filler></div>
	<div id=con>
	$cons
	</div>
	</div>
	</div>

	Eof;
	unset($styles, $elems, $cons, $statusl);
	$stwrite='chunk';
	chunk($headch);
	$timer = new DTimer();
	$t = 0;
	$tt = (int) (1000_000 / 30);
	$hidden = false;
	$hidt = 1;
	$tod = 10;
	$ii = 2;
	while (1) {
		$dt = $timer->tick();
		$t += $dt;
		$tlab->draw(1/$dt);
		$hidt -= $dt;
		$tod -= $dt;
		if ($hidt <= 0) {
			$hidden = !$hidden;
			$hidt = 0.5;
		}
		//$stylist->set("#board","display",$hidden?"none":"block");
		//$stylist->set("#board", "width", ((sin($t) + 1) / 2 * 25) . 'em');
		$stylist->present();
		//chunk("<!--".str_repeat("-",4096*1024)."-->"); // stress test
		if (connection_aborted() || !$alive) {
			error_log("bye!");
			return;
		}
		$buf = '';
		while (socket_recv($socket, $buf, 65536, 0) != false) {
		}
		if ($tod <= 0) {
			//break;
		}
		usleep(floor(max($tt-$dt,0)));
		//if(--$ii==0){ break; }
	}
	chunk(<<<Eof
	<meta http-equiv="refresh" content="0">

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
