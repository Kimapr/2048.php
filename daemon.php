#!/usr/bin/php
<?php
require 'vendor/autoload.php';
require 'lib/game.php';
require 'lib/net.php';
define("DIR", getenv("C2K48_DATA") ?: __DIR__ . "/tmp");
$path = DIR;
$path = implode('/', [realpath(dirname($path)), basename($path)]);
$path = "$path/daemon.sock";
ignore_user_abort(true);

use Amp\ByteStream\BufferedReader;
use Amp\Socket;
use function Amp\async;
use function Amp\delay;

if (file_exists($path)) {
	unlink($path);
}
$server = Socket\listen("unix://$path");

$games = [];

function close_game($client, string $name) {
	if (!isset($client->games[$name])) {
		return;
	}
	$viewer = $client->games[$name];
	$viewer->game->users--;
	$viewer->game->viewers -= (int) !$viewer->isplayer;
	unset($client->games[$name]);
}

while (($client = $server->accept()) !== null) {
	(function () use ($client, &$games) {
		$client = (object) [
			"rpc" => new ObjectRPC(
				new JsonMsgReader(new BufferedReader($client)),
				new JsonMsgWriter($client)
			),
			"games" => [],
		];
		$client->rpc->handler("new_game", function (...$arg) use (&$games) {
			do {
				$id = bin2hex(random_bytes(8));
			} while (isset($games[$id]));
			$games[$id] = (object) [];
			error_log("creating game {$id}");
			$game = $games[$id];
			$game->game = new x1p11local(...$arg);
			$game->pkey = bin2hex(random_bytes(16));
			$game->users = 0;
			$game->viewers = 0;
			async(function () use ($game, &$games, $id) {
				while (1) {
					delay(10);
					for ($t = 600; $t > 0 && ($kill = $game->users == 0); $t--) {
						delay(1);
					}
					if ($kill) {
						error_log("killing inactive game {$id}");
						unset($games[$id]);
						break;
					}
				}
			});
			return [$id, $game->pkey];
		});
		$client->rpc->handler("open_game", function (string $name, string $id, string $pkey = "") use (&$games, $client) {
			if (!isset($games[$id])) {
				error_log("game doesn't exist! {$id}");
				return;
			}
			if (isset($client->games[$name])) {
				close_game($client, $name);
			}
			$game = $games[$id];
			$viewer = (object) [
				"isplayer" => $game->pkey == $pkey,
				"game" => $game,
				"server" => new x1p11server($game->game, $client->rpc, $name),
			];
			$game->users++;
			$game->viewers += (int) !$viewer->isplayer;
			if (!$viewer->isplayer) {
				$client->rpc->handler("{$name}/move");
			}
			$client->games[$name] = $viewer;
			return (object) [
				"writable" => $viewer->isplayer,
			];
		});
		$client->rpc->handler("close_game", function (string $name) use ($client) {
			close_game($client, $name);
		});
		async($client->rpc->listen(...))
			->catch(function ($e) {error_log($e);})
			->finally(function () use ($client) {
			});
	})();
	unset($client);
}
