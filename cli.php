#!/usr/bin/php
<?php
include 'game.php';

class x1p11TextUI {
	private $game;
	private $board;
	private $ents;
	private $w, $h;
	private $lost;
	private $won;
	private $score;
	private const STATES=[
		" ",
		"new"=>"!",
		"slide"=>":",
		"merge"=>"+",
	];
	private function set($x, $y, $v = null, $new = 0) {
		$v = $v == null ? '.' : dechex(log($v, 2));
		$v = (object) ['v' => $v, 'new' => $new];
		$this->board[$y][$x] = $v;
	}
	private function expire() {
		for ($y = 0; $y < $this->h; $y++) {
			for ($x = 0; $x < $this->w; $x++) {
				$this->board[$y][$x]->new = false;
			}
		}
	}
	private function board_dump() {
		for ($y = 0; $y < $this->h; $y++) {
			echo ' ';
			for ($x = 0; $x < $this->w; $x++) {
				$v = $this->board[$y][$x];
				$v = $v->v . (self::STATES[$v->new]);
				echo $v;
			}
			echo "\n";
		}
	}
	public function __construct(x1p11 $game) {
		$this->game = $game;
		$this->board = [];
		$this->ents = [];
		$this->score = 0;
		[$this->w, $this->h] = $game->dimensions();
		for ($y = 0; $y < $this->h; $y++) {
			for ($x = 0; $x < $this->w; $x++) {
				$this->set($x, $y);
			}
		}
		/*
			// Debug
			$this->game->attach_handler(function ($type, $event) {
				//* //noise
					echo "Event: ";
					print_r($type->name);
					echo "\n";
					foreach ((array)$event as $k=>$v) {
						echo "	$k: ";
						print_r($v);
						echo "\n";
					}
					echo "\n";
				//* /
			});
			$deleting = [];
			$this->game->detach_handler($this->game->attach_handler(function ($type, $event) use (&$deleting) {
				$deleting[] = $event->pos;
			}));
			foreach ($deleting as [$x, $y]) {
				$this->game->set($x, $y);
			}
			foreach ([
				[10, 10, 3, 4],
				[2, 1, 2, 5],
				[1, 2, 1, 2],
				[2, 1, 2, 1],
			] as $y => $row) {
				foreach ($row as $x => $v) {
					$this->game->set($x, $y, 2 ** $v);
				}
			}
		*/
		$this->game->attach_handler($handler = function ($type, $event) use (&$handler) {
			switch ($type) {
			case BoardEventType::Slide:
				$ent = $this->ents[$event->id];
				[$x, $y] = $ent->pos;
				$this->set($x, $y);
				$ent->pos = $event->pos;
				[$x, $y] = $ent->pos;
				$this->set($x, $y, $ent->value, "slide");
				break;
			case BoardEventType::Merge:
				$src = $this->ents[$event->src];
				$dest = $this->ents[$event->dest];
				$handler(BoardEventType::Despawn, (object) [
					'id' => $event->src,
				]);
				$dest->value += $src->value;
				[$x, $y] = $dest->pos;
				$this->set($x, $y, $dest->value, "merge");
				break;
			case BoardEventType::Score:
				$this->score+=$event->value;
				break;
			case BoardEventType::Spawn:
				$ent = (object) [
					'pos' => $event->pos,
					'value' => $event->value,
				];
				$this->ents[$event->id] = $ent;
				[$x, $y] = $ent->pos;
				$this->set($x, $y, $ent->value, "new");
				break;
			case BoardEventType::Despawn:
				$ent = $this->ents[$event->id];
				unset($this->ents[$event->id]);
				[$x, $y] = $ent->pos;
				$this->set($x, $y);
				break;
			case BoardEventType::Lose:
				$this->lost = true;
				if ($this->won) {
					echo "Game over.\n";
				} else {
					echo "You lost the game.\n";
				}
				break;
			case BoardEventType::Win:
				$this->won = true;
				echo "You won!\n";
				break;
			}
		});
		$cmds = [
			'w' => Direction::Up,
			's' => Direction::Down,
			'a' => Direction::Left,
			'd' => Direction::Right,
			'q' => false,
		];
		$this->expire();
		while (1) {
			$this->board_dump();
			echo "Score: {$this->score}\n";
			if ($this->lost) {
				break;
			}
			//var_dump($this->game);
			$cmd = "";
			while (!isset($cmds[$cmd])) {
				$cmd = readline('> ');
				if ($cmd === false) {
					break 2;
				}
				$cmd = strtolower($cmd);
			}
			$cmd = $cmds[$cmd];
			if (!$cmd) {
				break;
			}
			$this->expire();
			$this->game->move($cmd);
		}
	}
}

$w = 4;
$h = 4;
$c = 2;
if (isset($argv[1])) {$w = $argv[1];}
if (isset($argv[2])) {$h = $argv[2];}
if (isset($argv[3])) {$c = $argv[3];}
if (!(is_numeric($w) && is_numeric($h) && is_numeric($c))) {
	echo <<<Eof
	2048.php CLI. WASD to move.
	Numbers are displayed as base 2 logarithms.
	Usage: 2048.php [width [height [initial blocks]]]

	Eof;
	return;
}

new x1p11TextUI(new x1p11($w, $h, $c));
