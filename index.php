<?php
include 'game.php';
include 'webutil.php';

ignore_user_abort(true);
set_time_limit(0);
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

class BlockAnims {
	private $stylist;
	private $name;
	private $ents;
	private $game;
	private $handler;
	private $anstack;
	private $antime;
	private $anbuf;
	private $anlayer;
	private $ancur;
	private $lmid;
	private $lanes;
	private static function valcolor($v) {
		if ($v < 2) {
			$c = self::valcolor(2);
			$c[3] = floor(max($v, 0) / 2 * 255);
			return $c;
		}
		$orv = $v;
		$v = log($v, 2);
		$h = (cos(($v ** 0.8) * M_PI / 11 * 3) / 2 + 0.5);
		$cv = (cos((($v - 1) ** 0.7 / 4) * M_PI) / 2 + 0.5);
		$c = hsvcol(1 / 12 + (1 / 12) * $h, 1 - $cv * 0.6, 1);
		$c = array_map(fn($c) => round($c * 255), $c);
		$c[4] = 255;
		return $c;
	}
	private function animflush($layer) {
		if ($this->anlayer) {
			$anim = (object) [];
			$anim->len = $this->anlayer == "spawn" ? 0.1 : 1;
			$anim->t = 0;
			$anim->anims = $this->anbuf;
			$this->anstack->push($anim);
			$this->antime += $anim->len;
		}
		$this->anlayer = $layer;
		$this->anbuf = $layer ? [] : null;
	}
	private function animpush($layer, $anim) {
		if ($this->anlayer != $layer) {
			$this->animflush($layer);
		}
		array_push($this->anbuf, $anim);
	}
	private function newz($lid) {
		if (!isset($this->lanes[$lid])) {
			$this->lanes[$lid] = 1;
		}
		$this->lanes[$lid]--;
		return $this->lanes[$lid];
	}
	public function flush() {
		$this->animflush(false);
	}
	public function __construct(StyleMutator $stylist, string $name, x1p11 $game, Callable $write) {
		[$w, $h] = $game->dimensions();
		$entc = $w * $h;
		$this->anstack = new SplDoublyLinkedList;
		$this->antime = 0;
		$this->anbuf = null;
		$this->anlayer = false;
		$this->ancur = null;
		$this->lmid = 0;
		$this->lanes = null;
		$this->name = $name;
		$this->game = $game;
		$this->stylist = $stylist;
		for ($i = 0; $i < $entc; $i++) {
			$ent = (object) [];
			$elid = $name . dechex($i);
			$elem = "<div class=b id=$elid><div>";
			$ent->name = '#' . $elid;
			$ent->num = new CursedNumber($stylist, $elid . "n", appendf($elem), 4);
			$ent->vis = false;
			$ent->real = ['color' => [0, 0, 0, 0], 'pos' => [0, 0], 'z' => 0, 'value' => 0];
			$ent->fake = $ent->real;
			$elem .= "</div></div>\n";
			$write($elem);
			$this->ents[] = $ent;
		}
		$this->draw(0);
		$this->handler = function ($type, $event) {
			if ($type != BoardEventType::Spawn) {
				return;
			}
			$value = $event->value;
			$pos = $event->pos;
			$ent = $this->ents[$event->id];
			$ent->vis = true;
			$ent->real = ['color' => self::valcolor($value), 'pos' => $pos, 'z' => 0, 'value' => $value];
			$ent->fake = $ent->real;
		};
		$game->attach_handler(function (...$a) {
			return ($this->handler)(...$a);
		});
		$this->handler = function ($type, $event) {
			if ($event->mid != $this->lmid) {
				$this->lmid = $event->mid;
				$this->lanes = [];
			}
			switch ($type) {
			case BoardEventType::Slide:
				$id = $event->id;
				$lane = $event->lane;
				$pos = $event->pos;
				$ent = $this->ents[$id];
				$this->animpush("slide", (object) [
					'id' => $id,
					'z' => $this->newz($lane),
					'pos' => [$ent->real["pos"], $pos],
				]);
				$ent->real['pos'] = $pos;
				break;
			case BoardEventType::Merge:
				$src = $event->src;
				$dest = $event->dest;
				$lane = $event->lane;
				$srce = $this->ents[$src];
				$deste = $this->ents[$dest];
				$srcv = $srce->real['value'];
				$destv = $deste->real['value'];
				$this->animpush("slide", (object) [
					'id' => $dest,
					'z' => $this->newz($lane),
					'value' => [$destv, $srcv + $destv],
				]);
				$this->animpush("slide", (object) [
					'id' => $src,
					'z' => $this->newz($lane),
					'pos' => [$srce->real['pos'], $deste->real['pos']],
					'postvis' => false,
				]);
				$deste->real['value'] = $destv + $srcv;
				break;
			case BoardEventType::Despawn:
				$id = $event->id;
				$ent = $this->ents[$id];
				$this->animpush("despawn", (object) [
					'id' => $id,
					'value' => [$ent->real['value'], 0],
					'postvis' => false,
				]);
				break;
			case BoardEventType::Spawn:
				$id = $event->id;
				$value = $event->value;
				$pos = $event->pos;
				$ent = $this->ents[$id];
				$this->animpush("spawn", (object) [
					'id' => $id,
					'value' => [0, $value],
					'pos' => [$pos, $pos],
					'z' => 0,
					'previs' => true,
				]);
				$ent->real['value'] = $value;
				$ent->real['pos'] = $pos;
				break;
			default:
				return;
			}
		};
	}
	private static function interp($a, $b, $t) {
		return $a * (1 - $t) + $b * $t;
	}
	private function update(float $dt) {
		if (!isset($this->ancur)) {
			if ($this->anstack->isEmpty()) {
				return false;
			}
			$this->ancur = $this->anstack->shift();
			$anime = $this->ancur;
			$this->antime -= $anime->len;
			foreach ($anime->anims as $anim) {
				$ent = $this->ents[$anim->id];
				if (isset($anim->previs)) {
					$ent->vis = $anim->previs;
				}
			}
		}
		$anime = $this->ancur;
		$dmul = max(($this->antime + ($anime->len - $anime->t)) * 8, 0.1);
		$ddt = $dt * $dmul;
		$anime->t += $ddt;
		$t = $anime->len > 0 ? min($anime->t, $anime->len) / $anime->len : 1;
		foreach ($anime->anims as $anim) {
			$ent = $this->ents[$anim->id];
			if (isset($anim->value)) {
				$ent->fake['value'] = round(self::interp($anim->value[0], $anim->value[1], $t));
			}
			if (isset($anim->pos)) {
				$ent->fake['pos'] = [
					self::interp($anim->pos[0][0], $anim->pos[1][0], $t),
					self::interp($anim->pos[0][1], $anim->pos[1][1], $t),
				];
			}
			if (isset($anim->z)) {
				$ent->fake['z'] = $anim->z;
			}
			$ent->fake['color'] = $this->valcolor($ent->fake['value']);
		}
		if ($anime->t >= $anime->len) {
			$dt = ($anime->t - $anime->len) / $dmul;
			$anime->t = $anime->len;
			foreach ($anime->anims as $anim) {
				$ent = $this->ents[$anim->id];
				if (isset($anim->postvis)) {
					$ent->vis = $anim->postvis;
				}
			}
			$this->ancur = null;
			return $this->update($dt);
		}
		return true;
	}
	public function draw(float $dt) {
		$ret = $this->update($dt);
		/*
			foreach ($this->ents as $id => $ent) {
				$ent->vis = false;
			}
			$this->game->detach_handler($this->game->attach_handler(function ($type, $event) {
				if ($type != BoardEventType::Spawn) {
					return;
				}
				$value = $event->value;
				$pos = $event->pos;
				$ent = $this->ents[$event->id];
				$ent->vis = true;
				$ent->real = ['color' => self::valcolor($value), 'pos' => $pos, 'z' => 0, 'value' => $value];
				$ent->fake = $ent->real;
			}));
		*/
		[$w, $h] = $this->game->dimensions();
		$stylist = $this->stylist;
		foreach ($this->ents as $id => $ent) {
			$stylist->set($ent->name, "display", $ent->vis ? 'flex' : 'none');
			$stylist->set($ent->name, "z-index", $ent->fake['z']);
			$stylist->set($ent->name, "justify-content", "center");
			$stylist->set($ent->name, "align-items", "center");
			$stylist->set($ent->name, "position", "absolute");
			$stylist->set($ent->name, "background-color", sprintf("#%02X%02X%02X%02X", ...$ent->fake['color']));
			[$x, $y] = $ent->fake['pos'];
			$stylist->set($ent->name, "left", sprintf("%f%%", ($x / $w) * 100));
			$stylist->set($ent->name, "top", sprintf("%f%%", ($y / $h) * 100));
			$stylist->set($ent->name, "width", sprintf("%f%%", (1 / $w) * 100));
			$stylist->set($ent->name, "height", sprintf("%f%%", (1 / $h) * 100));
			$stylist->set($ent->name, "font-size", "0");
			$ent->num->draw($ent->fake['value']);
		}
		return $ret;
	}
}

const MOVES = [
	'u' => Direction::Up,
	'd' => Direction::Down,
	'l' => Direction::Left,
	'r' => Direction::Right,
];

function game(&$quitf) {
	error_log("hai :3");
	$timer = new DTimer();
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
	$stwrite = appendf($styles);
	$stylist = new StyleMutator(callf($stwrite));
	foreach ([
		["l", "&lt;", 1, 2],
		["d", "v", 2, 3],
		["u", "^", 2, 1],
		["r", "&gt;", 3, 2],
	] as [$cmd, $label, $x, $y]) {
		$cons .=
			"<form method=post action=act/$uid/$cmd id=cb$cmd name=cb$cmd target=out></form>" .
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
	$game = new x1p11(4, 4);
	[$w, $h] = $game->dimensions();
	/*
		for($i=0;$i<$w*$h;$i++){
			$game->set($i%$w,floor($i/$w),2**($i+1));
		}
		$game->set(1,0,2);
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
	$game->attach_handler(function ($type, $event) use (&$lost, &$won, &$score) {
		switch ($type) {
		case BoardEventType::Score:
			$score += $event->value;
			break;
		case BoardEventType::Win:
			$won = true;
			break;
		case BoardEventType::Lose:
			$lost = true;
			break;
		}
	});
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
		//$stylist->set("#board","display",$hidden?"none":"block");
		//$stylist->set("#board", "width", ((sin($t) + 1) / 2 * 25) . 'em');
		//chunk("<!--".str_repeat("-",4096*1024)."-->"); // stress test
		$buf = '';
		while (socket_recv($socket, $buf, 65536, 0) != false) {
			if (MOVES[$buf]) {
				$game->move(MOVES[$buf]);
				$gamer->flush();
				if ($lost) {
					$stylist->set("#con", "display", "none");
					$stylist->set("#die", "display", "initial");
				}
				if ($won) {
					$stylist->set("#win", "display", "initial");
				}
			}
		}
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
			$toforce = 2;
		}
		if (connection_aborted() || !$alive) {
			error_log("bye!");
			return;
		}
		if ($lost && !$animating) {
			break;
		}
		usleep(floor(max($tt - $timer->tick(true) * 1000_000, 0)));
		//if(--$ii==0){ break; }
	}
	$tlab->draw(0);
	$blab->draw(0);
	$stylist->present();
	chunk("</body>");
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
