#!/usr/bin/php
<?php
chdir(dirname(realpath($argv[0])));
$cwd = getcwd();
$host = "127.0.0.1";
$port = "4444";
if (count($argv) == 3) {
	$host = $argv[1];
	$port = $argv[2];
} elseif (count($argv) != 1) {
	error_log("invalid argument");
	exit(1);
}
$f = fopen("http.conf", "w");
fwrite($f, <<<Eof
server.document-root = "$cwd"
server.bind = "$host"
server.port = $port
server.stream-response-body = 2
server.modules += ( "mod_cgi", "mod_rewrite" )
url.rewrite-once = ( "^/(.*)" => "/" )
cgi.assign = (".php" => "/usr/bin/php-cgi")
index-file.names = ( "index.php" )
Eof);
fclose($f);
mkdir("tmp");
$httpd=getenv("LIGHTTPD")?:"/usr/sbin/lighttpd";
system("$httpd -D -f ./http.conf");
