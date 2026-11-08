<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Асинхронность: публикация событий и отметки Inbox
|--------------------------------------------------------------------------
|
| Значения по умолчанию соответствуют ЧТЗ «Асинхронность и уведомления»
| (FR-NTF-171…174). Публикатор читает их при создании, поэтому литералов
| интервала, размера пачки, числа попыток, задержек и порогов в коде нет.
|
*/

return [
    // Транспорт событий (FR-NTF-206): redis — боевой адаптер Redis Streams,
    // in-memory — тесты и локальный путь M7, rabbitmq зарезервирован под M13.
    'transport' => (string) env('EVENTS_TRANSPORT', 'redis'),

    // Опциональный брокер M13 (FR-NTF-541…552, ЧТЗ 8.5): включается значением
    // `rabbitmq`, значения по умолчанию не меняются — транспорт остаётся `redis`.
    // Секретов и реальных адресов в репозитории нет (FR-NTF-549); имена
    // exchange и очередей живут только в конфигурации, а не в коде
    // (FR-OPS-508). Очередь сервиса (`RABBITMQ_QUEUE`) читает сервис
    // оповещений: у монолита только публикация в exchange.
    'rabbitmq' => [
        'host' => (string) env('RABBITMQ_HOST', 'rabbitmq'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => (string) env('RABBITMQ_USER', 'guest'),
        'password' => (string) env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => (string) env('RABBITMQ_VHOST', '/'),
        'heartbeat' => (int) env('RABBITMQ_HEARTBEAT', 30),
        'prefetch' => (int) env('RABBITMQ_PREFETCH', 50),
        // Таймаут подключения и чтения: ожидание без верхней границы запрещено.
        'timeout' => (int) env('RABBITMQ_TIMEOUT_SECONDS', 5),
        // Per-message TTL и число попыток до DLX (FR-NTF-548).
        'message_ttl_seconds' => (int) env('RABBITMQ_MESSAGE_TTL_SECONDS', 300),
        'max_attempts' => (int) env('RABBITMQ_MAX_ATTEMPTS', 5),
        'exchange' => (string) env('RABBITMQ_EXCHANGE', 'events'),
        'dlx_exchange' => (string) env('RABBITMQ_DLX_EXCHANGE', 'events.dlx'),
        // Домены маршрутизации по префиксу типа события (FR-NTF-542):
        // ключ — домен и полный тип события, привязка — `домен.#`.
        'routing' => [
            'order' => 'orders',
            'courier' => 'orders',
            'user' => 'contacts',
            'notification' => 'notifications',
        ],
    ],

    // Префикс имён потоков: изолирует окружения на общем Redis (FR-OPS-211).
    'stream_prefix' => (string) env('EVENTS_STREAM_PREFIX', ''),

    // Имена потоков (FR-OPS-211, FR-OPS-213): заказы, обратные события, контакты.
    'streams' => [
        'orders' => (string) env('EVENTS_STREAM_ORDERS', 'events.orders'),
        'notifications' => (string) env('EVENTS_STREAM_NOTIFICATIONS', 'events.notifications'),
        'contacts' => (string) env('EVENTS_STREAM_CONTACTS', 'events.contacts'),
    ],

    // Consumer group потребителя и имя процесса-потребителя (FR-NTF-202, FR-OPS-211).
    'consumer_group' => (string) env('EVENTS_CONSUMER_GROUP', 'notifications-service'),
    'consumer_name' => (string) env('EVENTS_CONSUMER_NAME', 'notifications-service-1'),

    // Обратные события сервиса оповещений: своя группа монолита (FR-NTF-207).
    // Поток заказов и поток уведомлений имеют независимые отметки о прочтении.
    'status_consumer_group' => (string) env('EVENTS_STATUS_CONSUMER_GROUP', 'courier'),
    'status_consumer_name' => (string) env('EVENTS_STATUS_CONSUMER_NAME', 'courier-1'),
    'status_consume_batch_size' => (int) env('EVENTS_STATUS_CONSUME_BATCH_SIZE', 50),

    // Интервал запуска потребителя обратных событий в секундах (FR-NTF-284).
    'status_consume_interval_seconds' => (int) env('EVENTS_STATUS_CONSUME_INTERVAL_SECONDS', 60),

    // Размер пачки потребления (FR-NTF-204, FR-OPS-211).
    'consume_batch_size' => (int) env('EVENTS_CONSUME_BATCH_SIZE', 50),

    // Задержка повторной выдачи неподтверждённых сообщений в секундах (FR-NTF-204).
    'pending_idle_seconds' => (int) env('EVENTS_PENDING_IDLE_SECONDS', 60),

    // Интервал запуска задачи расписания events:publish-outbox в секундах (FR-NTF-105).
    // Laravel повторяет задачу внутри минуты только для частот, на которые 60 делится
    // нацело: 1, 2, 5, 10, 15, 20, 30. Остальные значения округляются вверх до ближайшей
    // такой частоты (31…59 — до минуты), от 60 секунд — cron «каждые N минут», больше
    // 59 минут — ежечасно: см. App\Console\Scheduling\OutboxPublishFrequency.
    'publish_interval_seconds' => (int) env('EVENTS_PUBLISH_INTERVAL_SECONDS', 5),

    // Размер пачки одного прохода публикатора (FR-NTF-103, FR-NTF-105).
    'publish_batch_size' => (int) env('EVENTS_PUBLISH_BATCH_SIZE', 100),

    // Предел попыток передачи; после него событие считается необработанным (FR-NTF-108).
    'publish_max_attempts' => (int) env('EVENTS_PUBLISH_MAX_ATTEMPTS', 5),

    // Ряд задержек backoff в секундах: номер попытки выбирает элемент, последний повторяется.
    'publish_backoff_seconds' => array_map(
        static fn (string $value): int => (int) trim($value),
        explode(',', (string) env('EVENTS_PUBLISH_BACKOFF_SECONDS', '5,15,60,300,900')),
    ),

    // TTL блокировки прохода: держится на время прохода (FR-NTF-104).
    'publish_lock_ttl_seconds' => (int) env('EVENTS_PUBLISH_LOCK_TTL_SECONDS', 60),

    // Версия контракта события, попадающая в конверт (FR-NTF-112).
    'schema_version' => (string) env('EVENTS_SCHEMA_VERSION', 'v1'),

    // Каталог общих JSON Schema контрактов (FR-NTF-221): единственный экземпляр
    // схем для монолита и сервиса. Относительный путь считается от корня приложения.
    'schema_path' => (string) env('EVENTS_SCHEMA_PATH', 'contracts/notifications'),

    // Срок хранения отметок Inbox в днях (FR-NTF-137).
    'inbox_retention_days' => (int) env('EVENTS_INBOX_RETENTION_DAYS', 30),

    // Порог лага публикации в секундах для outbox:status (FR-OPS-104, FR-OPS-110).
    'max_publish_lag_seconds' => (int) env('EVENTS_MAX_PUBLISH_LAG_SECONDS', 60),

    // Предельное число необработанных событий для outbox:status (FR-OPS-110).
    'max_unprocessed_count' => (int) env('EVENTS_MAX_UNPROCESSED_COUNT', 0),
];
