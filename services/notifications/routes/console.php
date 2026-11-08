<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Расписание сервиса. Долгоживущие процессы потребителя и поллера живут
// в отдельных контейнерах, а расписание запускает короткий проход поллера
// отправки, чтобы повторы и новые уведомления уходили без ручного запуска
// (FR-NTF-272). Значения пачек и пауз поллер читает из конфигурации.
Schedule::command('notifications:send --once')
    ->everyMinute()
    ->withoutOverlapping();

// Публикатор обратных событий в поток `events.notifications` (FR-NTF-283).
// Интервал берётся из конфигурации и переводится в cron «каждые N минут»:
// литеральной частоты в расписании нет (FR-OPS-211).
$publishMinutes = min(59, max(1, (int) ceil(
    max(1, (int) config('notifications.outbox.publish_interval_seconds')) / 60,
)));

Schedule::command('notifications:publish-outbox')
    ->cron(sprintf('*/%d * * * *', $publishMinutes))
    ->withoutOverlapping()
    ->description('notifications:publish-outbox');

// Очистка журнала по срокам хранения (ЧТЗ 7, п. 8, FR-NTF-267). Cron-выражение
// и сроки — из конфигурации: литеральной частоты в расписании нет (FR-OPS-211).
Schedule::command('notifications:purge')
    ->cron((string) config('notifications.retention.purge_cron'))
    ->withoutOverlapping()
    ->description('notifications:purge');

// Очистка очереди необработанных по сроку хранения (FR-NTF-508): только
// расписание и осознанная команда. Кнопок и скрытого удаления нет; запись
// при повторе не удаляется. Cron-выражение — из конфигурации (FR-OPS-211).
Schedule::command('notifications:dlq-purge')
    ->cron((string) config('notifications.retention.purge_cron'))
    ->withoutOverlapping()
    ->description('notifications:dlq-purge');

// Очистка отозванных device-токенов по сроку хранения (FR-NTF-530, ЧТЗ 7.3
// п. 6): активные токены не удаляются, очистка — только команда и расписание.
// Cron-выражение — из конфигурации (FR-OPS-211).
Schedule::command('notifications:device-tokens:purge')
    ->cron((string) config('notifications.retention.purge_cron'))
    ->withoutOverlapping()
    ->description('notifications:device-tokens:purge');

// Снимок «дорогих» gauge метрик (FR-OPS-504…508): задача считает глубину
// DLQ, лаг и pending потоков и возраст старейшего события Outbox, а `/metrics`
// читает готовый документ из общего кэша. Cron-выражение из конфигурации.
Schedule::command('notifications:metrics-snapshot')
    ->cron((string) config('notifications.metrics.snapshot_cron'))
    ->withoutOverlapping()
    ->description('notifications:metrics-snapshot');

// Очистка использованных и истёкших токенов привязки Telegram (FR-NTF-412).
// У токенов свой срок хранения, расписание берётся из окружения (FR-OPS-211).
Schedule::command('notifications:telegram:purge')
    ->cron((string) config('notifications.retention.purge_cron'))
    ->withoutOverlapping()
    ->description('notifications:telegram:purge');
