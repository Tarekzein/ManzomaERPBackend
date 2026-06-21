FROM php:8.2-fpm-alpine

RUN apk add --no-cache git icu-dev libzip-dev oniguruma-dev \
    && docker-php-ext-install intl mbstring pdo_mysql zip pcntl

WORKDIR /var/www/html
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY . .
RUN composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
CMD ["php-fpm"]
