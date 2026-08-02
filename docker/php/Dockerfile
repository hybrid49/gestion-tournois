FROM php:8.4-fpm-alpine

RUN apk add --no-cache \
    postgresql-dev \
    icu-dev \
    libzip-dev \
    $PHPIZE_DEPS \
    && docker-php-ext-install -j$(nproc) pdo_pgsql intl opcache \
    && apk del $PHPIZE_DEPS

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/backend

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
