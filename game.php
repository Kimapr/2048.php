<?php

enum Direction: int {
case Down = 0;
case Left = 1;
case Up = 2;
case Right = 3;
}

enum BoardEventType: int {
case Spawn = 0;
case Slide = 1;
case Merge = 2;
case Despawn = 3;

case Score = 4;
case Win = 5;
case Lose = 6;
}

class x1p11 {
	private $dirs;
	private $board;
	private $w, $h;
	private $freeds;
	private $ents;
	private $won;
	private $lost;
	private $score;
	private $handlers;
	private $mid;
	private function pos($x, $y) {
		return $this->w * $y + $x;
	}
	private function spawn(Callable $handler, ?int $c = 1) {
		$cands = [];
		for ($y = 0; $y < $this->h; $y++) {
			for ($x = 0; $x < $this->w; $x++) {
				if ($this->board[$this->pos($x, $y)] == -1) {
					$cands[] = [$x, $y];
				}
			}
		}
		$c = min(count($cands), $c);
		if ($c == 0) {
			return;
		}
		$ids = array_slice($this->freeds, count($this->freeds) - $c);
		$rands = array_rand($cands, $c);
		if ($c == 1) {
			$rands = [$rands];
		}
		foreach (array_map(function ($k) use (&$cands) {return $cands[$k];}, $rands) as $i => [$x, $y]) {
			$handler(BoardEventType::Spawn, (object) [
				'pos' => [$x, $y],
				'id' => $ids[$i],
				'value' => rand(1, 2) * 2,
			]);
		}
	}
	private function safeset($i, $v) {
		if ($this->board[$i] != -1) {
			$this->apply(BoardEventType::Despawn, (object) [
				'id' => $this->board[$i],
			]);
		}
		$this->board[$i] = $v;
	}
	private function apply(BoardEventType $type, object $event) {
		switch ($type) {
		case BoardEventType::Slide:
			$id = $event->id;
			$pos = $event->pos;

			$ent = $this->ents[$id];
			$this->safeset($this->pos(...$pos), $id);
			$this->board[$this->pos(...$ent->pos)] = -1;
			$ent->pos = $pos;
			break;

		case BoardEventType::Merge:
			$src = $event->src;
			$dest = $event->dest;

			$entsrc = $this->ents[$src];
			$entdest = $this->ents[$dest];
			$this->apply(BoardEventType::Despawn, (object) [
				'id' => $src,
			]);
			$entdest->value += $entsrc->value;
			break;

		case BoardEventType::Score:
			$score = $event->value;

			$this->score += $score;
			break;

		case BoardEventType::Despawn:
			$id = $event->id;

			$ent = $this->ents[$event->id];
			$this->freeds[$event->id] = $event->id;
			unset($this->ents[$event->id]);
			$this->board[$this->pos(...$ent->pos)] = -1;
			break;

		case BoardEventType::Spawn:
			$id = $event->id;
			$pos = $event->pos;
			$value = $event->value;

			unset($this->freeds[$id]);
			$this->safeset($this->pos(...$pos), $id);
			$this->ents[$id] = (object) [
				'pos' => $pos,
				'value' => $value,
			];
			break;

		case BoardEventType::Lose:
			$this->lost = true;
			break;

		case BoardEventType::Win:
			$this->won = true;
			break;
		}
	}
	private function _move(Callable $handler, Direction $dir, bool $check = true) {
		[$ox, $oy, $mc, $mb, $cdx, $cdy, $bdx, $bdy] = $this->dirs[$dir->value];
		$moved = false;
		for ($c = 0; $c < $mc; $c++) {
			[$x🥺, $y🥺] = [$ox, $oy];
			[$x😈, $y😈] = [$ox, $oy];
			for ([$b🥺, $b😈] = [0, 0]; $b🥺 < $mb - 1 && $b😈 < $mb;) {
				$id🥺 = $this->board[$this->pos($x🥺, $y🥺)];
				$val🥺 = $id🥺 != -1 ? $this->ents[$id🥺]->value : null;
				$merges = $id🥺 == -1 ? 2 : 1;
				for (; $merges > 0;) {
					do {
						[$x😈, $y😈, $b😈] = [$x😈 + $bdx, $y😈 + $bdy, $b😈 + 1];
					} while ($b😈 <= $b🥺);
					if ($b😈 >= $mb) {
						break;
					}
					$id😈 = $this->board[$this->pos($x😈, $y😈)];
					$val😈 = $id😈 != -1 ? $this->ents[$id😈]->value : null;
					if ($id😈 != -1) {
						$merges--;
						if ($id🥺 == -1) {
							$handler(BoardEventType::Slide, (object) [
								'id' => $id😈,
								'pos' => [$x🥺, $y🥺],
								'lane' => $c,
							]);
							$val🥺 = $val😈;
							$val😈 = null;
							$id🥺 = $id😈;
							$id😈 = -1;
							$moved = true;
						} elseif ($val🥺 == $val😈) {
							$handler(BoardEventType::Merge, (object) [
								'src' => $id😈,
								'dest' => $id🥺,
								'lane' => $c,
							]);
							$val🥺 = $val🥺 + $val😈;
							$val😈 = null;
							$id🥺 = $id😈;
							$id😈 = -1;
							$handler(BoardEventType::Score, (object) [
								'value' => $val🥺,
							]);
							if ($check && !$this->won && $val🥺 >= 2048) {
								$handler(BoardEventType::Win, (object) []);
							}
							$moved = true;
						} else {
							[$x😈, $y😈, $b😈] = [$x😈 - $bdx, $y😈 - $bdy, $b😈 - 1];
							break;
						}
					}
				}
				[$x🥺, $y🥺, $b🥺] = [$x🥺 + $bdx, $y🥺 + $bdy, $b🥺 + 1];
			}
			[$ox, $oy] = [$ox + $cdx, $oy + $cdy];
		}
		if (!$check) {
			return $moved;
		}
		if ($moved) {
			$this->spawn($handler);
		}
		$lost = true;
		foreach (Direction::cases() as $cdir) {
			if ($cdir != $dir && $this->_move(fn() => null, $cdir, false)) {
				$lost = false;
				break;
			}
		}
		if ($lost) {
			$handler(BoardEventType::Lose, (object) []);
		}
	}
	private function handler($type, $event) {
		$event->mid = $this->mid;
		$this->apply($type, $event);
		foreach ($this->handlers as $f) {
			$f($type, $event);
		}
	}

	public function __construct(?int $w = 4, ?int $h = null, ?int $c = 2) {
		$h = $h == null ? $w : $h;
		$this->board = [];
		$this->freeds = [];
		$this->ents = [];
		$this->handlers = new SplObjectStorage();
		$this->mid = 0;
		$this->won = false;
		$this->lost = false;
		$this->score = 0;
		$id = 0;
		for ($i = 0; $i < $w * $h; $i++) {
			$this->board[] = -1;
			$this->freeds[$id] = $id;
			$id++;
		}
		array_reverse($this->freeds, true);
		[$mx, $my] = [$w - 1, $h - 1];
		$this->dirs = [
			[0, $my, $w, $h, 1, 0, 0, -1],
			[0, 0, $h, $w, 0, 1, 1, 0],
			[$mx, 0, $w, $h, -1, 0, 0, 1],
			[$mx, $my, $h, $w, 0, -1, -1, 0],
		];
		[$this->w, $this->h] = [$w, $h];
		$this->spawn([$this, 'apply'], $c);
	}
	public function dimensions() {
		return [$this->w, $this->h];
	}
	public function attach_handler(Callable $handler) {
		if (!is_object($handler)) {
			$handler = static function (...$a) use ($handler) {
				return $handler(...$a);
			};
		}
		$this->handlers->attach($handler);
		foreach ($this->ents as $id => $ent) {
			$handler(BoardEventType::Spawn, (object) [
				'id' => $id,
				'pos' => $ent->pos,
				'value' => $ent->value,
			]);
			if ($this->won) {
				$handler(BoardEventType::Win, (object) []);
			}
			if ($this->lost) {
				$handler(BoardEventType::Lose, (object) []);
			}
			$handler(BoardEventType::Score, (object) [
				'value' => $this->score,
			]);
		}
		return $handler;
	}
	public function detach_handler(Callable $handler) {
		$this->handlers->detach($handler);
	}
	public function move(Direction $dir) {
		$this->mid++;
		$this->_move([$this, 'handler'], $dir);
	}
	public function get(int $x, int $y) {
		$pos = $this->pos($x, $y);
		$id = $this->board[$pos];
		if ($this->board[$id] == -1) {
			return null;
		}
		return $this->ents[$id]->value;
	}
	public function set(int $x, int $y, ?int $value = null) {
		$pos = $this->pos($x, $y);
		$id = $this->board[$pos];
		if ($id != -1) {
			$this->handler(BoardEventType::Despawn, (object) [
				'id' => $id,
			]);
		}
		if ($value != null) {
			[$id] = array_slice($this->freeds, count($this->freeds) - 1);
			$this->handler(BoardEventType::Spawn, (object) [
				'id' => $id,
				'pos' => [$x, $y],
				'value' => $value,
			]);
		}
	}
}
