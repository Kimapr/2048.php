<?php
require 'vendor/autoload.php';
define("DIR", getenv("C2K48_TMP") ?: "./tmp");
$path = DIR;
$path = implode('/', [realpath(dirname($path)), basename($path)]);
$path = "unix://$path/daemon.sock";

use Amp\Socket;
use function Amp\async;

$server = Socket\listen($path);

function messages($sock) {
	while(1){
		$sock->read(4);
	}
}

while ($socket = $server->accept()) {
	async(function () use ($socket) {
		
	});
}
