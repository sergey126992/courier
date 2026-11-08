#!/bin/sh
set -e

APP_DIR=/var/www/html
LOCK_DIR="$APP_DIR/storage/framework/.env-init.lock"

# В образе только production-зависимости: dev-пакеты (Pint, PHPStan, Pest)
# ставятся при первом запуске в volume vendor.
if [ ! -x "$APP_DIR/vendor/bin/pint" ]; then
    composer install --no-interaction --prefer-dist --no-progress
fi

has_key() {
    grep -qE '^APP_KEY=[^[:space:]]+' "$APP_DIR/.env"
}

needs_init() {
    [ ! -f "$APP_DIR/.env" ] || ! has_key
}

init_environment() {
    if [ ! -f "$APP_DIR/.env" ]; then
        cp "$APP_DIR/.env.example" "$APP_DIR/.env"
        # Перевод строк приводим к LF, чтобы файл совпадал с тем, что ждёт git и dotenv.
        sed -i 's/\r$//' "$APP_DIR/.env"
        echo "Окружение инициализировано: создан .env из .env.example"
    fi

    if ! has_key; then
        php artisan key:generate --force
        echo "Окружение инициализировано: сгенерирован APP_KEY"
    fi
}

# Entrypoint используют три сервиса (app, queue, scheduler), поэтому
# инициализация идёт под общим локом в storage (общий volume).
if needs_init; then
    if mkdir "$LOCK_DIR" 2>/dev/null; then
        init_environment
        rmdir "$LOCK_DIR"
    else
        waited=0
        while [ -d "$LOCK_DIR" ] && [ "$waited" -lt 60 ]; do
            sleep 1
            waited=$((waited + 1))
        done
        if needs_init; then
            init_environment
        fi
    fi
fi

exec "$@"
