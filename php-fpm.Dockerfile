FROM php:8.2-fpm
RUN docker-php-ext-configure sockets && \
    docker-php-ext-install sockets
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
