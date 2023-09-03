# 2048.php

An implementation of 2048 in PHP. Supports arbitrary board sizes.

## Web UI

**NOTE**: Unfinished.

Uses streamed HTML and CSS to create dynamic content without any client-side
JavaScript.

CGI. Communication between the main game process and control scripts (invoked
by form buttons targeting an invisible iframe) is done via UNIX domain datagram
sockets. Will not work on Windows, get a real operating system.

For convenience, a CLI script is provided that runs a pre-configured lighttpd
instance:

    ./server.php [address <port>]

## CLI

Simple CLI. Displays numbers on the board as base 2 logarithms in hexadecimal
(1=>2, 2=>4, b=>2048, etc.).

    ./cli.php [width [height [initial blocks]]]
