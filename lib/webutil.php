<?php

$chunking = false;

function chunk_start(int $code = 200, string $content_type = "text/html; charset=utf-8") {
	global $chunking;
	if ($chunking) {
		throw new Exception("already chunking");
	}

	$chunking = true;
	if (ob_get_level() == 0) {
		ob_start();
	}

	http_response_code($code);
	header("Content-type: $content_type");
	header("X-Content-Type-Options: nosniff");
	//header("Transfer-encoding: chunked");
	header('X-Accel-Buffering: no');
	flush();
}

function chunk($str) {
	global $chunking;
	if (!$chunking) {
		throw new Exception("not chunking");
	}

	//printf("%s\r\n", dechex(strlen($str)));
	//printf("%s\r\n", $str);
	echo $str; // nginx doesn't like chunking...
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

function obcapture(Callable $f, ...$a) {
	ob_start();
	$f(...$a);
	return ob_get_clean();
}

class DTimer {
	private $last;
	public function __construct() {
		$this->last = 0;
		$this->tick();
	}
	public function tick(bool $soft = false) {
		$cur = hrtime(true);
		$dt = $cur - $this->last;
		if (!$soft) {
			$this->last = $cur;
		}
		return $dt / 1000_000_000;
	}
}

class Averager {
	private $v, $w, $n;
	public function __construct() {
		$this->l = null;
	}
	public function push($v, $w) {
		$l = new self;
		[$l->v, $l->w, $l->n] = [$v, $w, $this->n];
		$this->n = $l;
	}
	public function avg($maxw) {
		$w = 0;
		$v = 0;
		for ($l = $this->n;isset($l); $l = $l->n) {
			$lw = min($l->w, $maxw - $w);
			$w += $lw;
			$v += $l->v * $lw;
			if ($w >= $maxw) {
				$l->n = null;
				break;
			}
		}
		return $v / $w;
	}
}

function appendf(&$s) {return function ($a) use (&$s) {$s .= $a;};}
function callf(&$f) {return function (...$a) use (&$f) {return $f(...$a);};}

function hsvcol($h, $s, $v) {
	$c = $v * $s;
	$h = fmod($h * 6, 6);
	$x = $c * (1 - abs(fmod($h, 2) - 1));
	$h = floor($h);
	$r = [0, 0, 0];
	switch ($h) {
	case 0:
		$r = [$c, $x, 0];
		break;
	case 1:
		$r = [$x, $c, 0];
		break;
	case 2:
		$r = [0, $c, $x];
		break;
	case 3:
		$r = [0, $x, $c];
		break;
	case 4:
		$r = [$x, 0, $c];
		break;
	case 5:
		$r = [$c, 0, $x];
		break;
	}
	$m = $v - $c;
	$r = array_map(function ($n) use ($m) {return $n + $m;}, $r);
	return $r;
}

class StyleMutator {
	private $current;
	private $dirty;
	private $write;
	public function __construct(Callable $write, ?StyleMutator $from = null) {
		if (isset($from)) {
			$this->current =  &$from->current;
			$this->dirty =  &$from->dirty;
		} else {
			$this->current = [];
			$this->dirty = [];
		}
		$this->write = $write;
	}
	public function set($i, $k, $v) {
		$this->dirty[$k][$i] = $v;
		if (isset($this->current[$k][$i]) && $this->current[$k][$i] == $v) {
			unset($this->dirty[$k][$i]);
		}
	}
	public function present($force = false) {
		$str = "<style>%s</style>\n";
		$byval = [];
		foreach ($this->dirty as $k => $l) {
			foreach ($l as $i => $v) {
				$byval[$k . ":" . $v][] = $i;
			}
		}
		foreach ($byval as $v => $l) {
			$byval[$v] = sprintf("%s{%s}", implode(',', $l), $v);
		}
		$byval = implode('', $byval);
		$written = false;
		if (strlen($byval) > 0) {
			($this->write)(sprintf($str, $byval));
			$written = true;
		}
		if ($force && !$written) {
			($this->write)("<!---->\n");
			$written = true;
		}
		foreach ($this->dirty as $k => $l) {
			foreach ($l as $i => $v) {
				$this->current[$k][$i] = $v;
			}
		}
		$this->dirty = [];
		return $written;
	}
}

const SI_BIGGER = [
	['k', 1000 ** 1],
	['M', 1000 ** 2],
	['G', 1000 ** 3],
	['T', 1000 ** 4],
	['P', 1000 ** 5],
	['E', 1000 ** 6],
	['Z', 1000 ** 7],
	['Y', 1000 ** 8],
	['R', 1000 ** 9],
	['Q', 1000 ** 10],
];
const SI_SMALLER = [
	['m', 1000 ** -1],
	['μ', 1000 ** -2],
	['n', 1000 ** -3],
	['p', 1000 ** -4],
	['a', 1000 ** -5],
	['z', 1000 ** -6],
	['y', 1000 ** -7],
	['r', 1000 ** -8],
	['q', 1000 ** -9],
];

function fnumfitweak($n, $m, $trim = true) {
	$dd = max($m - strlen(sprintf("%.0f", floor($n))) - 1, 0);
	$s = sprintf("%.{$dd}f", $n);
	if ($trim) {
		$s = rtrim(preg_replace('/(\.)([0-9]*?)(0*)$/', '\1\2', $s), '.');
	}
	return $s == '' ? '0' : $s;
}

function fnumfit($n, $m) {
	$p = '';
	$s = fnumfitweak($n, $m);
	foreach (SI_SMALLER as [$vp, $vm]) {
		if (!($n > 0 && $s == 0)) {
			break;
		}
		$p = $vp;
		$s = fnumfitweak($n / $vm, $m - 1);
	}
	if ($p != '') {
		return [$s, $p];
	}
	foreach (SI_BIGGER as [$vp, $vm]) {
		if (strlen($s . $p) <= $m) {
			break;
		}
		$p = $vp;
		$s = fnumfitweak($n / $vm, $m - 1);
	}
	return [$s, $p];
}

class CursedNumber {
	private $stylist;
	private $maxlen;
	private $name;
	private const DIGITS = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, '.' => 's'];
	public function __construct( ? StyleMutator &$stylist = null, ?string $name = null,  ? Callable $write = null, int $maxlen = 5) {
		$this->stylist =  &$stylist;
		$this->maxlen = $maxlen;
		if (isset($stylist)) {
			if (!(isset($name) && isset($write))) {
				throw new Exception("invalid argument");
			}
			$this->name = $name;
			$str = "<span id=$name>";
			$state = "none";
			for ($i = 0; $i < $maxlen; $i++) {
				foreach (self::DIGITS as $v => $d) {
					$en = $this->elname('d', $i, $d);
					$str .= "<span id=$en>$v</span>";
					$this->stylist->set('#' . $en, "display", $state);
				}
			}
			foreach ([...SI_BIGGER, ...SI_SMALLER] as [$d, $v]) {
				$en = $this->elname('p', $d);
				$str .= "<span id=$en>$d</span>";
				$this->stylist->set('#' . $en, "display", $state);
			}
			$str .= "</span>";
			$write($str);
		}
	}
	private function elname($type, ...$l) {
		$name = $this->name;
		switch ($type) {
		case "d" :
			return "{$name}n{$l[0]}{$l[1]}";
		case "p" :
			return "{$name}p{$l[0]}";
		}
	}
	public function draw($n) {
		$n = fnumfit($n, $this->maxlen);
		for ($i = 0; $i < $this->maxlen; $i++) {
			foreach (self::DIGITS as $v => $d) {
				$en = $this->elname('d', $i, $d);
				$state = "none";
				if (isset($n[0][$i]) && $n[0][$i] == $v) {
					$state = "initial";
				}
				$this->stylist->set('#' . $en, "display", $state);
			}
		}
		foreach ([...SI_BIGGER, ...SI_SMALLER] as [$d, $v]) {
			$en = $this->elname('p', $d);
			$state = "none";
			if ($n[1] == $d) {
				$state = "initial";
			}
			$this->stylist->set('#' . $en, "display", $state);
		}
	}
}

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
		$clv = max(1, $orv - 2 ** 11);
		$bs = cos((($v - 11)) * M_PI / 40 + 1 * M_PI) / 2 + 0.5;
		$bv = 1 - (($clv ** (-log($clv) / 400)));
		$c = hsvcol(1 / 12 + (1 / 12) * $h * (1 - $bv) + 1 / 3 * ($bv) + 1 / 6 * $bv * $bs, 1 - $cv * 0.6 * (1 - $bv), 1);
		$c = array_map(fn($c) => round($c * 255), $c);
		$c[3] = 255;
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
				$ent->fake['value'] = self::interp($anim->value[0], $anim->value[1], $t ** (-4));
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
			$ent->num->draw(round($ent->fake['value']));
		}
		return $ret;
	}
}
