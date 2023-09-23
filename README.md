# 2048.php

An implementation of 2048 in PHP. Supports arbitrary board sizes.

## Web UI

Uses streamed HTML and CSS to create dynamic content without any client-side
JavaScript.

Run an instance with Docker Compose, an example configuration is available at
`docker-compose.yml.example`.

## CLI

Simple CLI. Displays numbers on the board as base 2 logarithms in hexadecimal
(1=>2, 2=>4, b=>2048, etc.).

    ./cli.php [width [height [initial blocks]]]
