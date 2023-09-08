<?php

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

function obcapture(Callable $f) {
	ob_start();
	$f();
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
