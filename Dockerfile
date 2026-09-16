FROM php:8.5-cli-alpine

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

COPY composer.json ./
RUN composer install --no-interaction --no-progress --prefer-dist

COPY . .

CMD ["composer", "test"]
