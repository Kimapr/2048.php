<?php

use Amp\ByteStream\BufferedReader;
use Amp\ByteStream\BufferException;
use Amp\ByteStream\WritableStream;
use Amp\Cancellation;
use Amp\DeferredFuture;
use function Amp\async;

interface ReadableObjectStream {
	public function recv(): mixed;
}

interface WritableObjectStream {
	public function send(mixed $msg): void;
}

class JsonMsgReader implements ReadableObjectStream {
	public function __construct(
		private BufferedReader $stream
	) {}
	public function recv(?Cancellation $cancellation = null): mixed {
		try {
			return json_decode($this->stream->readUntil("\n", $cancellation));
		} catch (BufferException $e) {
			return null;
		}
	}
}

class JsonMsgWriter implements WritableObjectStream {
	public function __construct(
		private WritableStream $stream
	) {}
	public function send(mixed $msg): void {
		$this->stream->write(json_encode($msg) . "\n");
	}
}

interface ProcedureCalls {
	public function handler(string $fname,  ? Callable $handler = null) : void;
	public function call(string $fname, ...$args): mixed;
}

class ObjectRPC implements ProcedureCalls {
	private array $futures = [];
	private array $handlers = [];
	private int $counter = 0;
	public function __construct(
		private ReadableObjectStream $read,
		private WritableObjectStream $write
	) {}
	private function handleMessage(string $act, string | int $id, mixed $data, object $msg) {
		switch ($act) {
		case "call":
			$handler = $this->handlers[$msg->func];
			$arg = (array) $data;
			async(function () use ($arg, $id, $handler) {
				try {
					$this->write->send((object) [
						"act" => "return",
						"id" => $id,
						"data" => $handler(...$arg),
					]);
				} catch (Throwable $e) {
					try {
						$this->write->send((object) [
							"act" => "throw",
							"id" => $id,
							"data" => (string) $e,
						]);
					} catch (Throwable $e) {
						error_log($e);
					}
				}
			});
			break;
		case "return":
		case "throw":
			if (!isset($this->futures[$id])) {
				break;
			}
			$future = $this->futures[$id];
			switch ($act) {
			case "return":
				$future->complete($data);
				break;
			case "throw":
				$future->error(new Exception($data));
				break;
			}
			break;
		}
	}
	public function listen(?Cancellation $cancellation = null) {
		while (($msg = $this->read->recv($cancellation)) !== NULL) {
			$this->handleMessage($msg->act, $msg->id, $msg->data, $msg);
		}
	}
	public function call(string $fname, ...$args): mixed {
		$future = new DeferredFuture;
		$id = $this->counter++;
		$this->write->send((object) [
			"act" => "call",
			"id" => $id,
			"func" => $fname,
			"data" => $args,
		]);
		$this->futures[$id] = $future;
		return $future->getFuture()->await();
	}
	public function handler(string $fname,  ? Callable $handler = null) : void {
		if ($handler === NULL) {
			unset($this->handlers[$fname]);
			return;
		}
		$this->handlers[$fname] = $handler;
	}
}

class x1p11server {
	private array $handlers;
	private ?ObjectRPC $rpc;
	public function __construct(x1p11 $game, ObjectRPC $rpc, string $name) {
		$handlers = [];
		$handler = function ($fname, $fn) use ($game, $rpc, $name, &$handlers) {
			$fname = "{$name}/{$fname}";
			$handlers[] = $fname;
			$rpc->handler($fname, $fn);
		};
		$handler("dimensions", function () use ($game) {
			return $game->dimensions();
		});
		$fids = [];
		$fi = 0;
		$handler("attach_handler", function ($handler) use ($game, $rpc, &$fids, &$fi) {
			$fids[$fi] = $game->attach_handler(function ($type, $event) use ($game, $rpc, $handler, &$fids, $fi) {
				try {
					return $rpc->call($handler, $type->value, $event);
				} catch (Exception $e) {
					error_log($e);
				}
			});
			return $fi++;
		});
		$handler("detach_handler", function ($handler) use ($game, &$fids) {
			$game->detach_handler($fids[$handler]);
			unset($fids[$handler]);
		});
		$handler("move", function ($dir) use ($game) {
			return $game->move(Direction::from($dir));
		});
		$handler("get", function ($x, $y) use ($game) {
			return $game->get($x, $y);
		});
		$handler("set", function ($x, $y, $v) use ($game) {
			$game->set($x, $y, $v);
		});
		$this->rpc = $rpc;
		$this->handlers = $handlers;
	}
	public function close() {
		if (!isset($this->rpc)) {
			return;
		}
		foreach ($this->handlers as $fname) {
			$this->rpc->handler($fname);
		}
		unset($this->rpc);
		unset($this->handlers);
	}
	public function __destruct() {
		$this->close();
	}
}

class x1p11client implements x1p11 {
	private array $handlers = [];
	private int $lastid = 0;
	public function __construct(
		private ObjectRPC $rpc,
		private string $name
	) {}
	public function __destruct() {
		foreach ($this->handlers as $h => $fname) {
			$this->detach_handler($h);
		}
	}
	public function dimensions(): array {
		return $this->rpc->call("{$this->name}/dimensions");
	}
	public function attach_handler(Callable $handler): mixed {
		$fname = "{$this->name}/handlers/{$this->lastid}";
		$this->lastid++;
		$this->rpc->handler($fname, function ($type, $event) use ($handler) {
			return $handler(BoardEventType::from($type), $event);
		});
		$h = $this->rpc->call("{$this->name}/attach_handler", $fname);
		$this->handlers[$h] = $fname;
		return $h;
	}
	public function detach_handler(mixed $handler): void {
		if (!isset($this->handlers[$handler])) {
			return;
		}
		$this->rpc->call("{$this->name}/detach_handler", $handler);
		$this->rpc->handler($this->handlers[$handler]);
		unset($this->handlers[$handler]);
	}
	public function move(Direction $dir): void {
		$this->rpc->call("{$this->name}/move", $dir->value);
	}
	public function get(int $x, int $y): ?float {
		return $this->rpc->call("{$this->name}/get", $x, $y);
	}
	public function set(int $x, int $y, ?float $v = null): void {
		$this->rpc->call("{$this->name}/set", $x, $y, $v);
	}
}
