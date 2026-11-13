#!/bin/sh
# Прогон тестов с жёсткой проверкой результата: «risky» не считается зелёным.
# Pest 4 учитывает failOnRisky в коде возврата, проверка вывода — страховка.
set -u

LOG=/tmp/pest.log

php artisan test "$@" > "$LOG" 2>&1
status=$?

cat "$LOG"

if grep -qE 'RISKY|aborted' "$LOG"; then
    echo "Найден risky-тест: прогон не считается зелёным" >&2
    exit 1
fi

exit "$status"
