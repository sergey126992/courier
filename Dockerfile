# syntax=docker/dockerfile:1

# Базовый слой: PHP 8.5 FPM (Alpine) с расширениями, нужными приложению.
FROM php:8.5-fpm-alpine AS php-base

# OPcache в PHP 8.5 собран в ядро и включён по умолчанию, поэтому в списке
# расширений его нет: docker-php-ext-install opcache падает (нет каталога modules).
RUN apk add --no-cache libpq postgresql-dev \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS linux-headers \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pcntl sockets \
    && apk del --no-network .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/app.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# Слой зависимостей: composer install от непривилегированного пользователя.
FROM php-base AS vendor

ENV COMPOSER_HOME=/tmp/composer \
    COMPOSER_ALLOW_SUPERUSER=0

RUN adduser -u 1000 -D -S builder

COPY --chown=builder:builder composer.json composer.lock ./

USER builder

RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress --optimize-autoloader

# ---------------------------------------------------------------------------
# Слой фронтенд-ассетов: npm ci и сборка Vite.
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./

RUN npm ci --no-audit --no-fund

COPY resources ./resources
COPY vite.config.js ./

# resources/js/app.js импортирует ESM-бандл Livewire из vendor; в этой стадии
# PHP-зависимостей нет, поэтому берём из стадии vendor только этот каталог.
COPY --from=vendor /var/www/html/vendor/livewire/livewire/dist ./vendor/livewire/livewire/dist

RUN npm run build

# ---------------------------------------------------------------------------
# Runtime-слой: код, зависимости и собранные ассеты; процесс идёт от app.
FROM php-base AS runtime

RUN addgroup -g 1000 app && adduser -u 1000 -D -S -G app app

COPY --chown=app:app docker/php/entrypoint.sh /usr/local/bin/entrypoint
# Перевод строк приводим к LF: файл может попасть в контекст с CRLF (Windows).
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint && chmod +x /usr/local/bin/entrypoint

COPY --chown=app:app . .
COPY --from=vendor --chown=app:app /var/www/html/vendor ./vendor
COPY --from=assets --chown=app:app /app/public/build ./public/build

USER app

ENTRYPOINT ["/usr/local/bin/entrypoint"]
CMD ["php-fpm"]
