<?php

declare(strict_types=1);

use App\Console\Scheduling\OutboxPublishFrequency;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Истёкшие ключи идемпотентности не копятся: срок хранения задан конфигурацией.
Schedule::command('orders:purge-idempotency-keys')->hourly();

// Публикатор Outbox: интервал берётся из config('events.publish_interval_seconds'),
// литерала в расписании нет (FR-NTF-105, FR-NTF-171). Описание задачи повторяет
// имя из ЧТЗ, чтобы проход был узнаваем в выводе schedule:list.
OutboxPublishFrequency::apply(
    Schedule::command('outbox:publish')->description('events:publish-outbox'),
    (int) config('events.publish_interval_seconds'),
);

// Отметки Inbox не копятся: срок хранения задан конфигурацией (FR-NTF-137).
Schedule::command('processed-messages:purge')->daily();

// Потребитель обратных событий сервиса оповещений (FR-NTF-207, FR-NTF-284):
// проход обновляет проекцию `order_notification_status` для карточки заказа.
// Частота берётся из конфигурации, литерала в расписании нет.
OutboxPublishFrequency::apply(
    Schedule::command('notifications:consume-status --once')
        ->description('events:consume-notification-status')
        ->withoutOverlapping(),
    (int) config('events.status_consume_interval_seconds'),
);
