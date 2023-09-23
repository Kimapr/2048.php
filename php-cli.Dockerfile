FROM php:8.2-cli
RUN docker-php-ext-configure sockets && \
    docker-php-ext-install sockets
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
RUN mkdir -p /appdata && chown -R www-data /appdata
