<?php
require 'vendor/autoload.php';
require 'lib/game.php';
require 'lib/webutil.php';
require 'lib/net.php';

ignore_user_abort(true);
set_time_limit(0);
define("DIR", realpath(getenv("C2K48_DATA") ?: __DIR__ . "/tmp"));
define("SOCKPATH", DIR . "/daemon.sock");

$alive = true;

use Amp\ByteStream\BufferedReader;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Socket;
use function Amp\async;
use function Amp\delay;
use function Amp\Future\await;
use function Amp\trapSignal;

async(function () {
	global $alive;
	try {
		while (1) {
			if (trapSignal(SIGTERM) == SIGTERM) {
				$alive = false;
				error_log("signal");
			}
		}
	} catch (\Throwable $e) {}
});

function game($ginfo) {
	error_log("hai :3");
	$timer = new DTimer();
	global $alive;
	$ginfo->listen->finally(function () {
		$alive = false;
	});
	chunk_start();
	$styles = "";
	$cons = "";
	$elems = "";
	$statusl = "";
	$stwrite = appendf($styles);
	$stylist = new StyleMutator(callf($stwrite));
	foreach ([
		[Direction::Left->value, "&lt;", 1, 2],
		[Direction::Down->value, "v", 2, 3],
		[Direction::Up->value, "^", 2, 1],
		[Direction::Right->value, "&gt;", 3, 2],
	] as [$cmd, $label, $x, $y]) {
		$cons .=
		"<form method=post action=move?" . http_build_query(["id" => $ginfo->id, "pkey" => $ginfo->pkey, "dir" => $cmd]) . " id=cb$cmd name=cb$cmd target=out></form>" .
			"<div class=conbc id=cbd$cmd><button class=conb form=cb$cmd>$label</button></div>";
		$stylist->set("#cbd$cmd", "grid-row-start", $y);
		$stylist->set("#cbd$cmd", "grid-column-start", $x);
	}
	$statusl .= "<div class=fps>";
	$statuslf = appendf($statusl);
	$tlab = new CursedNumber($stylist, "tcc", $statuslf, 4);
	$statusl .= " fps, ";
	$btlab = new CursedNumber($stylist, "btc", $statuslf, 4);
	$statusl .= "B tx (";
	$blab = new CursedNumber($stylist, "bcc", $statuslf, 4);
	$statusl .= "B/s)</div><div id=win>You won!</div><div>score: ";
	$scor = new CursedNumber($stylist, "scc", $statuslf);
	$tlab->draw(0);
	$scor->draw(0);
	$game = $ginfo->game;
	[$w, $h] = $game->dimensions();
	/*
		$game = new x1p11(16, 16);
		[$w, $h] = $game->dimensions();
		for($i=0;$i<$w*$h;$i++){
			//$game->set($i%$w,floor($i/$w),2**($i+1));
			$game->set($i%$w,floor($i/$w),1.1**$i-1);
		}
		//$game->set(1,0,2);
	//*/
	$gamer = new BlockAnims($stylist, "gg", $game, appendf($elems));
	$statusl .= "</div>";
	unset($cmd, $label);
	$bvalc = 16;
	unset($i, $elem, $elid, $bval, $bvald, $elnc, $elnid);
	$stylist->set("#win", "display", "none");
	$stylist->set("#die", "display", "none");
	$stylist->present();
	$styles .= "<style>\n";
	for ($csize = 1; $csize <= 100; $csize *= 1.1) {
		$fsize = $csize / 3;
		$styles .= sprintf("@container cell (min-width: %svw){div{font-size:%svw}}\n", fnumfitweak($csize, 4, 0), fnumfitweak($fsize, 4, 0));
	}
	$styles .= "</style>\n";
	$headch = <<<Eof
	<!DOCTYPE html>
	<head><meta name="viewport" content="width=device-width" /><title>2048.php</title></head><body>
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
	#bcont2{container-name:bbox;container-type:size;width:100%;height:100%;display:flex;justify-content:center;align-items:center}
	#board{aspect-ratio:$w/$h;border:solid 1px black;position:relative}
	@container bbox (max-aspect-ratio:$w/$h) {#board{width:100%}}
	@container bbox not (max-aspect-ratio:$w/$h) {#board{height:100%}}
	#if{display:none}
	#con{display:grid;aspect-ratio:1;}
	.conbc{width:100%;height:100%;}
	.conb{font-size:1em;width:100%;height:100%}
	.b{container-name:cell;container-type:size;user-select:none}
	.fps{font-size:0.7em}
	form{display:none}
	</style>
	$styles<iframe id=if name=out></iframe>
	<div id=cont>
	<div id=bcont><div id=bcont2><div id=board>
	$elems</div></div></div>
	<div id=sidec>
	<div id=status>$statusl</div>
	<div class=filler></div>
	<span id=die>Game over.</span><div id=con>
	$cons
	</div>
	</div>
	</div>

	Eof;
	unset($styles, $elems, $cons, $statusl);
	$datas = 0;
	$datotal = 0;
	$stwrite = function ($data) use (&$datas) {
		chunk($data);
		$datas += strlen($data);
	};
	$stwrite($headch);
	$score = 0;
	$lost = false;
	$won = false;
	$game->attach_handler(function ($type, $event) use (&$lost, &$won, &$score, $gamer, $stylist) {
		switch ($type) {
		case BoardEventType::Score:
			$score += $event->value;
			break;
		case BoardEventType::Win:
			$won = true;
			$stylist->set("#win", "display", "initial");
			break;
		case BoardEventType::Lose:
			$lost = true;
			$stylist->set("#con", "display", "none");
			$stylist->set("#die", "display", "initial");
			break;
		case BoardEventType::MoveComplete:
			$gamer->flush();
			break;
		}
	});
	if (!$ginfo->writable) {
		$stylist->set("#con", "display", "none");
	}
	$t = 0;
	$tt = (int) (1000_000 / 30);
	$hidden = false;
	$tofpsu = 0;
	$todu = 0;
	$toforce = 0;
	$tod = 10;
	$ii = 2;
	$avgdata = new Averager;
	$avgfps = new Averager;
	while (1) {
		$dt = $timer->tick();
		$avgfps->push(1 / $dt, $dt);
		$avgdata->push($datas / $dt, $dt);
		$datotal += $datas;
		$datas = 0;
		$todu -= $dt;
		$tofpsu -= $dt;
		$toforce -= $dt;
		$t += $dt;
		$tod -= $dt;
		$buf = '';
		if ($tofpsu <= 0) {
			$tlab->draw(round($avgfps->avg(1)));
			$tofpsu = 1;
		}
		if ($todu <= 0) {
			$blab->draw($avgdata->avg(5));
			$btlab->draw($datotal);
			$todu = 5;
		}
		$animating = $gamer->draw($dt);
		$scor->draw($score);
		if ($stylist->present($toforce <= 0)) {
			$toforce = 5;
		}
		if (connection_aborted() || !$alive) {
			error_log($alive ? "bye! (conclose)" : "bye! (signal)");
			return;
		}
		if ($lost && !$animating) {
			break;
		}
		delay(floor(max($tt - $timer->tick(true) * 1000_000, 0)) / 1000_000);
	}
	$tlab->draw(0);
	$blab->draw(0);
	$stylist->present();
	chunk("</body>");
	chunk_end();
}
function mgame($ginfo) {
	error_log("hai bot!");
	chunk_start(200, "text/json");
	$game = $ginfo->game;
	$gaming = true;
	$clock = 0;
	$hid = $game->attach_handler(function ($type, $event) use ($game, &$gaming) {
		chunk(json_encode([$type->name, $event]) . "\n");
		$clock = 0;
		if ($type == BoardEventType::Lose) {
			$gaming = false;
		}
	});
	await([
		async(function () use (&$gaming, &$clock, $hid, $ginfo) {
			while ($gaming && !connection_aborted()) {
				delay(1);
				$clock++;
				if ($clock >= 10) {
					$clock = 0;
					chunk(" ");
				}
			}
			error_log("closing");
			$ginfo->game->__destruct();
			unset($ginfo->game);
			$ginfo->rpc->call("close_game", $ginfo->name);
			$ginfo->cancel->cancel();
		}),
		$ginfo->listen->catch(function ($e) {
			if (!is_a($e, CancelledException::class)) {
				error_log("bad error!");
				throw $e;
			}
		}),
	]);
	error_log("bye bot");
}
$path = explode('/', $_SERVER["PATH_INFO"]);
$path = array_values(array_filter($path, function ($e) {
	return $e != '';
}));
$spath = implode('/', $path);
parse_str($_SERVER["QUERY_STRING"], $args);
function open_game($rpc, $name, $id, $pkey) {
	$ginfo = $rpc->call("open_game", $name, $id, $pkey);
	if ($ginfo == null) {
		return;
	}
	$game = new x1p11client($rpc, $name);
	$ginfo->game = $game;
	$ginfo->rpc = $rpc;
	$ginfo->id = $id;
	$ginfo->name = $name;
	$ginfo->pkey = $pkey;
	return $ginfo;
}
if ($spath == '') {
	echo <<<EOF
	<!DOCTYPE html>
	<head><title>2048</title></head>
	<h1>2048.php</h1>
	<p>An implementation of the popular puzzle game <a href="https://en.wikipedia.org/wiki/2048_(video_game)">2048</a>.
	<a href="https://gitlab.com/Kimapr/2048.php">Source code</a></p>
	<p>As it relies heavily on progressive HTML rendering and advanced CSS features (e.g. <code>display: none</code>) it might not work in older web browsers.</p>
	<p><a href="create">Start game</a></p>
	<pre>
	EOF;
} else {
	$socket = Socket\connect("unix://" . SOCKPATH);
	$rpc = new ObjectRPC(
		new JsonMsgReader(new BufferedReader($socket)),
		new JsonMsgWriter($socket)
	);
	$cancel = new DeferredCancellation;
	$listen = async(function ($cancel) use ($rpc) {
		$rpc->listen($cancel);
	}, $cancel->getCancellation());
	if ($spath == "play" || $spath == "mplay") {
		$ginfo = open_game($rpc, "game", $args["id"], isset($args["pkey"]) ? $args["pkey"] : "");
		if ($ginfo) {
			$ginfo->listen = $listen;
			$ginfo->cancel = $cancel;
			if ($spath == "play") {
				game($ginfo);
			} else {
				mgame($ginfo);
			}
		} else {
			http_response_code(404);
			header("Content-type: text/plain");
			echo "game gone.\n";
		}
	} else if ($spath == "move") {
		error_log("moving {$args["dir"]}");
		$ginfo = open_game($rpc, "game", $args["id"], $args["pkey"]);
		if ($ginfo) {
			$ginfo->game->move(Direction::from($args["dir"]));
		} else {
			http_response_code(404);
			header("Content-type: text/plain");
			echo "game gone.\n";
		}
	} else if ($spath == "create") {
		$game = $rpc->call("new_game");
		error_log("creating new game {$game[0]}");
		$query = http_build_query(["id" => $game[0], "pkey" => $game[1]]);
		header("Location: play?" . $query);
		header("Content-type: text/plain");
		echo $query . "\n";
	} else {
		http_response_code(404);
		echo "not found\n";
	}
	if (isset($ginfo->game)) {
		$ginfo->game->__destruct();
		$rpc->call("close_game", $ginfo->name);
		unset($ginfo);
	}
}
