#!/bin/sh
set -e

APP_DIR=/var/www/html
ENV_LOCK_DIR="$APP_DIR/storage/framework/.env-init.lock"
VENDOR_LOCK_DIR="$APP_DIR/storage/framework/.vendor-install.lock"

# В образе только production-зависимости: dev-пакеты (Pint, PHPStan, Pest)
# ставятся при первом запуске в общий volume vendor.
has_dev_vendor() {
    [ -x "$APP_DIR/vendor/bin/pint" ]
}

install_dev_vendor() {
    if mkdir "$VENDOR_LOCK_DIR" 2>/dev/null; then
        # Блокировку снимаем всегда, даже при ошибке установки.
        if composer install --no-interaction --prefer-dist --no-progress; then
            rmdir "$VENDOR_LOCK_DIR" 2>/dev/null || true
        else
            rmdir "$VENDOR_LOCK_DIR" 2>/dev/null || true
            return 1
        fi
    else
        # Зависимости ставит другой контейнер: ждём, пока он закончит.
        waited=0
        while [ -d "$VENDOR_LOCK_DIR" ] && [ "$waited" -lt 600 ]; do
            sleep 1
            waited=$((waited + 1))
        done
        if ! has_dev_vendor; then
            install_dev_vendor
        fi
    fi
}

if ! has_dev_vendor; then
    install_dev_vendor
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
        # Перевод строк приводим к LF, чтобы файл совпадал с тем, что ждёт dotenv.
        sed -i 's/\r$//' "$APP_DIR/.env"
        echo "Окружение инициализировано: создан .env из .env.example"
    fi

    if ! has_key; then
        php artisan key:generate --force
        echo "Окружение инициализировано: сгенерирован APP_KEY"
    fi
}

# Entrypoint используют три сервиса (app, worker, scheduler), поэтому
# инициализация идёт под общим локом в storage (общий volume).
if needs_init; then
    if mkdir "$ENV_LOCK_DIR" 2>/dev/null; then
        init_environment
        rmdir "$ENV_LOCK_DIR"
    else
        waited=0
        while [ -d "$ENV_LOCK_DIR" ] && [ "$waited" -lt 60 ]; do
            sleep 1
            waited=$((waited + 1))
        done
        if needs_init; then
            init_environment
        fi
    fi
fi

# Самопроверка при старте (FR-OPS-209): конфигурация, читаемость общих схем и
# доступность базы. База может подниматься чуть позже — даём несколько попыток;
# некорректная конфигурация не проходит никогда, и процесс останавливается.
check_attempts=0
while ! php artisan notifications:check --no-interaction; do
    check_attempts=$((check_attempts + 1))

    if [ "$check_attempts" -ge 10 ]; then
        echo "Проверка конфигурации не пройдена: сервис оповещений остановлен" >&2
        exit 1
    fi

    sleep 1
done

exec "$@"
