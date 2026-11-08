<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Сервис оповещений: транспорт, контракты, доступ и процессы
|--------------------------------------------------------------------------
|
| Все значения читаются только из окружения (FR-OPS-211, FR-OPS-213):
| литералов размеров пачек, интервалов, попыток и имён потоков в коде нет.
| Значения по умолчанию безопасны для локальной разработки: реальных
| адресов и секретов в репозитории нет (FR-OPS-212).
|
*/

/*
| Список значений из окружения: запятая как разделитель, пустые элементы
| отбрасываются. Так наборы каналов, локалей и типов событий не дублируются
| в коде и меняются только переменной окружения (FR-OPS-421…425).
*/
$csv = static function (string $value): array {
    return array_values(array_filter(
        array_map(
            static fn (string $item): string => trim($item),
            explode(',', $value),
        ),
        static fn (string $item): bool => $item !== '',
    ));
};

return [
    // Включённые каналы доставки (FR-NTF-245): канал из матрицы, которого нет
    // в этом списке, отбрасывается без создания уведомления и без ошибки.
    'enabled_channels' => array_values(array_filter(
        array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) env('NOTIFICATIONS_ENABLED_CHANNELS', 'email,push')),
        ),
        static fn (string $value): bool => $value !== '',
    )),

    // Матрица «тип события → роль получателя → каналы» (FR-NTF-241, FR-NTF-242).
    // Правила M7 перенесены из монолита без изменений; пользовательских
    // настроек на M11 нет. Смен курьера в матрице нет: они уведомлений
    // не порождают и подтверждаются без записей журнала (FR-NTF-243).
    'policy' => [
        'order.created' => [
            'sender' => ['email'],
        ],
        'order.assigned' => [
            'sender' => ['push'],
            'courier' => ['push'],
        ],
        'order.picked_up' => [
            'sender' => ['email'],
        ],
        'order.delivered' => [
            'sender' => ['email', 'push'],
        ],
        'order.failed' => [
            'sender' => ['email'],
        ],
        'order.cancelled' => [
            'sender' => ['email'],
            'courier' => ['push'],
        ],
    ],

    // Роли, которым уведомление уходит только при наличии назначения
    // у заказа (FR-NTF-242): условие выражается признаком правила, а не
    // запросом к базе из домена.
    'requires_assignment' => [
        'order.cancelled' => ['courier'],
    ],

    // Языки текстов уведомлений (FR-NTF-247): локаль берётся из события,
    // неподдерживаемая локаль заменяется языком по умолчанию.
    'locales' => [
        'supported' => array_values(array_filter(
            array_map(
                static fn (string $value): string => strtolower(trim($value)),
                explode(',', (string) env('NOTIFICATIONS_SUPPORTED_LOCALES', 'ru')),
            ),
            static fn (string $value): bool => $value !== '',
        )),
        'fallback' => env('NOTIFICATIONS_LOCALE_FALLBACK', 'ru'),
    ],

    // Ссылка трекинга в текстах уведомлений (FR-NTF-251). Адрес берётся
    // из окружения: реальных адресов в репозитории нет (FR-OPS-212).
    'tracking' => [
        'url' => env('NOTIFICATIONS_TRACKING_URL', 'http://localhost:8000/track/{tracking_code}'),
    ],

    // Транспорт событий: единственный адаптер этапа M11 — Redis Streams (FR-NTF-206).
    'transport' => [
        'driver' => env('NOTIFICATIONS_TRANSPORT', 'redis'),
        // Префикс потоков изолирует окружения на общем Redis.
        'stream_prefix' => env('NOTIFICATIONS_STREAM_PREFIX', ''),
        'streams' => [
            'orders' => env('NOTIFICATIONS_STREAM_ORDERS', 'events.orders'),
            'notifications' => env('NOTIFICATIONS_STREAM_NOTIFICATIONS', 'events.notifications'),
            'contacts' => env('NOTIFICATIONS_STREAM_CONTACTS', 'events.contacts'),
        ],
        // Consumer group: своя отметка о прочтении и pending у сервиса (FR-NTF-207).
        'consumer_group' => env('NOTIFICATIONS_CONSUMER_GROUP', 'notifications-service'),
        'consumer_name' => env('NOTIFICATIONS_CONSUMER_NAME', 'notifications-service-1'),
        // У потока контактов свой получатель в группе: у потоков
        // независимые подтверждения и pending (FR-NTF-207, FR-NTF-231).
        'contacts_consumer_name' => env(
            'NOTIFICATIONS_CONTACTS_CONSUMER_NAME',
            env('NOTIFICATIONS_CONSUMER_NAME', 'notifications-service-1').'-contacts',
        ),
        // Размер пачки потребления и порог «зависших» сообщений (FR-OPS-211).
        'batch_size' => (int) env('NOTIFICATIONS_CONSUME_BATCH_SIZE', 50),
        'pending_idle_seconds' => (int) env('NOTIFICATIONS_PENDING_IDLE_SECONDS', 60),
        // Блокирующее ожидание при чтении потока и предел сообщений при захвате pending.
        'block_milliseconds' => (int) env('NOTIFICATIONS_BLOCK_MILLISECONDS', 1000),
        'reclaim_count' => (int) env('NOTIFICATIONS_RECLAIM_COUNT', 100),

        // Опциональный брокер M13 (FR-NTF-541…552, ЧТЗ 8.5): адаптер
        // подключается значением `rabbitmq`, параметры приходят из окружения,
        // чтобы переключение транспорта не потребовало литералов в коде. Секретов и реальных адресов
        // в репозитории нет (FR-NTF-549), значения безопасны для разработки.
        // Имена exchange и очередей живут только здесь (FR-OPS-508).
        'rabbitmq' => [
            'host' => env('RABBITMQ_HOST', 'rabbitmq'),
            'port' => (int) env('RABBITMQ_PORT', 5672),
            'user' => env('RABBITMQ_USER', 'guest'),
            'password' => env('RABBITMQ_PASSWORD', 'guest'),
            'vhost' => env('RABBITMQ_VHOST', '/'),
            'heartbeat' => (int) env('RABBITMQ_HEARTBEAT', 30),
            'prefetch' => (int) env('RABBITMQ_PREFETCH', 50),
            // Таймаут подключения и чтения: ожидание без верхней границы запрещено.
            'timeout' => (int) env('RABBITMQ_TIMEOUT_SECONDS', 5),
            // Per-message TTL и число попыток до DLX (FR-NTF-548, ЧТЗ 8.5).
            'message_ttl_seconds' => (int) env('RABBITMQ_MESSAGE_TTL_SECONDS', 300),
            'max_attempts' => (int) env('RABBITMQ_MAX_ATTEMPTS', 5),
            'exchange' => env('RABBITMQ_EXCHANGE', 'events'),
            'queue' => env('RABBITMQ_QUEUE', 'notifications.events'),
            'dlx_exchange' => env('RABBITMQ_DLX_EXCHANGE', 'events.dlx'),
            'dlq_queue' => env('RABBITMQ_DLQ_QUEUE', 'notifications.dlq'),
            // Домены маршрутизации по префиксу типа события (FR-NTF-542):
            // ключ маршрутизации — домен и полный тип, привязка — `домен.#`.
            'routing' => [
                'order' => 'orders',
                'courier' => 'orders',
                'user' => 'contacts',
                'notification' => 'notifications',
            ],
        ],
    ],

    // Общие JSON Schema контрактов (FR-NTF-221): каталог монтируется только для чтения.
    'contracts' => [
        'path' => env('NOTIFICATIONS_SCHEMA_PATH', base_path('contracts/notifications')),
        'dialect' => env('NOTIFICATIONS_SCHEMA_DIALECT', 'https://json-schema.org/draft/2020-12/schema'),
        'schema_version' => env('NOTIFICATIONS_SCHEMA_VERSION', 'v1'),
    ],

    // Служебный доступ к API сервиса (FR-OPS-206, FR-OPS-211): токен только из окружения.
    'api' => [
        'token' => env('NOTIFICATIONS_API_TOKEN', 'dev-notifications-token'),
        'rate_limit' => [
            'attempts' => (int) env('NOTIFICATIONS_API_RATE_LIMIT_ATTEMPTS', 60),
            'decay_seconds' => (int) env('NOTIFICATIONS_API_RATE_LIMIT_WINDOW_SECONDS', 60),
        ],
        // Размер страницы журнала по умолчанию и верхняя граница `limit`
        // (ЧТЗ 8.6): значений-литералов в контроллерах нет.
        'page_limit' => (int) env('NOTIFICATIONS_API_PAGE_LIMIT', 50),
        'max_page_limit' => (int) env('NOTIFICATIONS_API_MAX_PAGE_LIMIT', 200),
        // База URI `type` в ответах application/problem+json (ЧТЗ 8.5).
        'problem_type_base' => env('NOTIFICATIONS_API_PROBLEM_TYPE_BASE', 'https://notifications.local/problems'),
    ],

    // Пауза между итерациями потребителя в секундах.
    'consumer' => [
        'sleep_seconds' => (int) env('NOTIFICATIONS_CONSUMER_SLEEP_SECONDS', 1),
    ],

    // Поллер отправки: интервал опроса, размер пачки, предел попыток, ряд backoff
    // и таймаут одного прохода (FR-NTF-172, FR-OPS-211).
    'sender' => [
        'poll_interval_seconds' => (int) env('NOTIFICATIONS_SENDER_POLL_INTERVAL_SECONDS', 5),
        'batch_size' => (int) env('NOTIFICATIONS_SENDER_BATCH_SIZE', 50),
        'max_attempts' => (int) env('NOTIFICATIONS_MAX_ATTEMPTS', 5),
        'backoff_seconds' => array_map(
            static fn (string $value): int => (int) trim($value),
            explode(',', (string) env('NOTIFICATIONS_BACKOFF_SECONDS', '10,30,60,120,300')),
        ),
        // Разброс задержки повтора: доля от базовой паузы (FR-NTF-272,
        // FR-OPS-213). Джиттер детерминирован по идентификатору уведомления,
        // поэтому готовность к повтору не «мигает» между проходами поллера.
        'backoff_jitter_ratio' => (float) env('NOTIFICATIONS_BACKOFF_JITTER_RATIO', 0.2),
        // Предел длины безопасного сообщения об ошибке в журнале попыток
        // (FR-NTF-273, FR-OPS-202).
        'attempt_message_limit' => (int) env('NOTIFICATIONS_ATTEMPT_MESSAGE_LIMIT', 200),
        // Таймаут одной попытки отправки (FR-OPS-205): то же значение получает
        // SMTP-транспорт письма, поэтому ожидание провайдера ограничено.
        'timeout_seconds' => (int) env('NOTIFICATIONS_SEND_TIMEOUT_SECONDS', 30),
        // Пауза, после которой уведомление, взятое в работу проходом поллера,
        // считается брошенным и снова доступно к отправке. Значение обязано
        // быть строго больше времени обработки пачки (FR-OPS-205), иначе ещё
        // отправляемое уведомление возьмут повторно; guard-тест проверяет это.
        'retry_after_seconds' => (int) env('NOTIFICATIONS_SENDER_RETRY_AFTER_SECONDS', 1800),
    ],

    // Публикатор обратных событий в поток `events.notifications` (FR-NTF-283).
    // Интервал, пачка, попытки, задержки и TTL блокировки прохода — только из
    // окружения: литералов в расписании и коде нет (FR-OPS-211, FR-OPS-213).
    'outbox' => [
        'publish_interval_seconds' => (int) env('NOTIFICATIONS_OUTBOX_PUBLISH_INTERVAL_SECONDS', 60),
        'publish_batch_size' => (int) env('NOTIFICATIONS_OUTBOX_PUBLISH_BATCH_SIZE', 100),
        'publish_max_attempts' => (int) env('NOTIFICATIONS_OUTBOX_PUBLISH_MAX_ATTEMPTS', 5),
        'publish_backoff_seconds' => array_map(
            static fn (string $value): int => (int) trim($value),
            explode(',', (string) env('NOTIFICATIONS_OUTBOX_PUBLISH_BACKOFF_SECONDS', '5,15,60,300,900')),
        ),
        'publish_lock_ttl_seconds' => (int) env('NOTIFICATIONS_OUTBOX_PUBLISH_LOCK_TTL_SECONDS', 60),
    ],

    // Сроки хранения журнала, попыток, push-журнала, Inbox и ключей
    // дедупликации (ЧТЗ 7, п. 8): короткие сроки у служебных таблиц,
    // длинные — у журнала уведомлений и попыток.
    'retention' => [
        'days' => (int) env('NOTIFICATIONS_RETENTION_DAYS', 90),
        'attempt_days' => (int) env('NOTIFICATIONS_ATTEMPT_RETENTION_DAYS', 90),
        'push_days' => (int) env('NOTIFICATIONS_PUSH_RETENTION_DAYS', 30),
        'inbox_days' => (int) env('NOTIFICATIONS_INBOX_RETENTION_DAYS', 30),
        'dedup_days' => (int) env('NOTIFICATIONS_DEDUP_RETENTION_DAYS', 30),
        // Расписание очистки: cron-выражение из окружения (FR-OPS-211).
        'purge_cron' => env('NOTIFICATIONS_PURGE_CRON', '30 3 * * *'),
    ],

    // Очередь необработанных M13 (FR-NTF-508, ЧТЗ 7.3 п. 6): срок хранения
    // записи и лимиты порций чтения читаются только из окружения. Срок
    // согласован с журналом уведомлений (90 дней).
    'dlq' => [
        'retention_days' => (int) env('NOTIFICATIONS_DLQ_RETENTION_DAYS', 90),
        'page_limit' => (int) env('NOTIFICATIONS_DLQ_PAGE_LIMIT', 50),
        'max_page_limit' => (int) env('NOTIFICATIONS_DLQ_MAX_PAGE_LIMIT', 200),
    ],

    // Токены устройств M13 (FR-NTF-530, ЧТЗ 7.3 п. 6): очистка по сроку
    // касается только отозванных токенов, активные не удаляются.
    'device_tokens' => [
        'retention_days' => (int) env('NOTIFICATIONS_DEVICE_TOKEN_RETENTION_DAYS', 30),
    ],

    // Пороги состояния сервиса (FR-OPS-204): превышение даёт degraded в /health,
    // процесс при этом продолжает работу. Значения только из окружения.
    'health' => [
        // Допустимый возраст самой старой необработанной записи, секунды.
        'lag_threshold_seconds' => (int) env('NOTIFICATIONS_HEALTH_LAG_THRESHOLD_SECONDS', 300),
        // Допустимое число сообщений, ожидающих подтверждения.
        'pending_threshold' => (int) env('NOTIFICATIONS_HEALTH_PENDING_THRESHOLD', 100),
    ],

    // Метрики M13 (FR-OPS-501…508, ЧТЗ 8.5): включение `/metrics`, TTL кэша
    // «дорогих» gauge-значений и границы гистограммы длительности отправки.
    // Порогов и границ в коде нет — только здесь (FR-OPS-508).
    'metrics' => [
        'enabled' => (bool) env('NOTIFICATIONS_METRICS_ENABLED', true),
        'snapshot_ttl_seconds' => (int) env('NOTIFICATIONS_METRICS_SNAPSHOT_TTL_SECONDS', 30),
        'histogram_buckets' => array_map(
            static fn (string $value): float => (float) trim($value),
            explode(',', (string) env('NOTIFICATIONS_METRICS_HISTOGRAM_BUCKETS', '0.1,0.5,1,2,5,10')),
        ),
        // Расписание снимка «дорогих» gauge (FR-OPS-508): литеральной частоты
        // в коде нет, выражение приходит из окружения (FR-OPS-211).
        'snapshot_cron' => (string) env('NOTIFICATIONS_METRICS_SNAPSHOT_CRON', '* * * * *'),
        // Хранилище снимка общее для процесса отправки, задачи расписания
        // и HTTP: память процесса для этого не годится, поэтому по умолчанию
        // Redis. Значения не персональные — только счётчики и gauge (FR-OPS-512).
        'cache_store' => (string) env('NOTIFICATIONS_METRICS_CACHE_STORE', 'redis'),
        'cache_prefix' => (string) env('NOTIFICATIONS_METRICS_CACHE_PREFIX', 'notifications:metrics:'),
        // Белый список меток (FR-OPS-507): метка вне списка в экспорт не попадает,
        // идентификаторы и персональные данные запрещены.
        'labels' => ['channel', 'state', 'event_type', 'stream', 'result', 'platform', 'provider'],
    ],

    // Предпочтения получателя и цепочка каналов M12 (FR-NTF-451…462,
    // FR-NTF-471…482, ЧТЗ 8.5). Матрица M11 остаётся единственным дефолтом
    // (FR-NTF-459); здесь только параметры проверки, тихих часов, замещения
    // и наборы типов событий по ролям (FR-NTF-460, FR-UI-408).
    'preferences' => [
        // Таймзона окна тихих часов по умолчанию (ЧТЗ 12.2, п. 2).
        'default_timezone' => env('NOTIFICATIONS_PREFERENCES_DEFAULT_TIMEZONE', 'Europe/Moscow'),
        // Порядок цепочки замещения: email всегда последний (FR-NTF-472).
        'channel_priority' => $csv((string) env(
            'NOTIFICATIONS_PREFERENCES_CHANNEL_PRIORITY',
            'push,telegram,email',
        )),
        // Предел длины цепочки замещения (FR-NTF-479).
        'max_fallback_depth' => (int) env('NOTIFICATIONS_PREFERENCES_MAX_FALLBACK_DEPTH', 3),
        // Коды детерминированного отказа, при которых канал заменяется
        // следующим (FR-NTF-477). Инфраструктурные сбои (`429`, `5xx`,
        // таймаут) сюда не входят: их лечит повтор по backoff.
        'fallback_error_codes' => $csv((string) env(
            'NOTIFICATIONS_FALLBACK_ERROR_CODES',
            'TELEGRAM_CHAT_NOT_FOUND,TELEGRAM_REQUEST_REJECTED,'
                .'NOTIFICATION_CHANNEL_UNAVAILABLE,NOTIFICATION_PROVIDER_REJECTED,'
                .'NOTIFICATION_DEVICE_UNREGISTERED,NOTIFICATION_PUSH_TOKEN_MISSING',
        )),
        // Критичные типы событий: email отключить нельзя, тихие часы их
        // не откладывают (FR-NTF-455, FR-NTF-499).
        'critical_event_types' => $csv((string) env(
            'NOTIFICATIONS_CRITICAL_EVENT_TYPES',
            'order.delivered,order.failed',
        )),
        // Каналы, отправка которых откладывается окном тихих часов (FR-NTF-492).
        'quiet_hours_channels' => $csv((string) env(
            'NOTIFICATIONS_QUIET_HOURS_CHANNELS',
            'push,telegram',
        )),
        // Типы событий, доступные роли получателя (FR-NTF-460, FR-UI-408).
        // Диспетчер и администратор автоматических уведомлений не получают,
        // поэтому их наборы пусты (ЧТЗ 12.1).
        'sender_event_types' => $csv((string) env(
            'NOTIFICATIONS_SENDER_EVENT_TYPES',
            'order.created,order.assigned,order.picked_up,order.delivered,order.failed,order.cancelled',
        )),
        'courier_event_types' => $csv((string) env(
            'NOTIFICATIONS_COURIER_EVENT_TYPES',
            'order.assigned,order.cancelled',
        )),
        'dispatcher_event_types' => $csv((string) env('NOTIFICATIONS_DISPATCHER_EVENT_TYPES', '')),
        'admin_event_types' => $csv((string) env('NOTIFICATIONS_ADMIN_EVENT_TYPES', '')),
    ],

    // Канал Telegram и бот M12 (FR-NTF-431…443, ЧТЗ 8.5). Токен бота и
    // секрет вебхука читаются только из окружения: в репозитории их нет
    // (FR-OPS-212, FR-NTF-441). По умолчанию канал выключен — `telegram`
    // в `enabled_channels` добавляет окружение осознанно.
    'telegram' => [
        'bot_token' => env('NOTIFICATIONS_TELEGRAM_BOT_TOKEN', ''),
        'bot_username' => env('NOTIFICATIONS_TELEGRAM_BOT_USERNAME', ''),
        // Базовый URL API Telegram; в тестах заменяется фейковым клиентом.
        'api_url' => env('NOTIFICATIONS_TELEGRAM_API_URL', 'https://api.telegram.org'),
        // Клиент Bot API: `http` в рабочем окружении, `fake` — в тестах
        // и демонстрации без сети (FR-NTF-433).
        'client' => env('NOTIFICATIONS_TELEGRAM_CLIENT', 'http'),
        // Таймаут одного запроса и число повторов транспорта (FR-NTF-443).
        'timeout_seconds' => (int) env('NOTIFICATIONS_TELEGRAM_TIMEOUT_SECONDS', 10),
        'retries' => (int) env('NOTIFICATIONS_TELEGRAM_RETRIES', 2),
        // Лимит символов сообщения и значение `retry_after` по умолчанию
        // (FR-NTF-435, FR-NTF-436).
        'message_limit' => (int) env('NOTIFICATIONS_TELEGRAM_MESSAGE_LIMIT', 4096),
        'retry_after_default_seconds' => (int) env('NOTIFICATIONS_TELEGRAM_RETRY_AFTER_DEFAULT_SECONDS', 30),
        // Срок жизни токена привязки и срок хранения использованных
        // и истёкших токенов (FR-NTF-412, ЧТЗ 7, п. 8).
        'link_token_ttl_seconds' => (int) env('NOTIFICATIONS_TELEGRAM_LINK_TOKEN_TTL_SECONDS', 900),
        'link_token_retention_days' => (int) env('NOTIFICATIONS_TELEGRAM_LINK_TOKEN_RETENTION_DAYS', 7),
        // Режим получения апдейтов: long polling или вебхук (FR-NTF-442).
        'updates_mode' => env('NOTIFICATIONS_TELEGRAM_UPDATES_MODE', 'polling'),
        'poll_timeout_seconds' => (int) env('NOTIFICATIONS_TELEGRAM_POLL_TIMEOUT_SECONDS', 25),
        'poll_batch_size' => (int) env('NOTIFICATIONS_TELEGRAM_POLL_BATCH_SIZE', 50),
        'poll_interval_seconds' => (int) env('NOTIFICATIONS_TELEGRAM_POLL_INTERVAL_SECONDS', 1),
        // Секрет вебхука сравнивается постоянным по времени способом, а его
        // публичный адрес нужен команде регистрации вебхука (FR-NTF-442).
        'webhook_secret' => env('NOTIFICATIONS_TELEGRAM_WEBHOOK_SECRET', ''),
        'webhook_url' => env('NOTIFICATIONS_TELEGRAM_WEBHOOK_URL', ''),
    ],

    // Push-провайдер: на этапе M11 единственная реализация — журнал-симулятор.
    'push' => [
        'provider' => env('NOTIFICATIONS_PUSH_PROVIDER', 'fake'),

        // Реальный провайдер FCM (FR-NTF-521…524, ЧТЗ 8.5). Креды читаются
        // только из окружения; в репозитории их нет (FR-NTF-522). Пустые
        // `project_id` и `credentials_path` означают «провайдер не настроен»
        // и допустимы, пока выбран `fake`.
        'fcm' => [
            'project_id' => env('FCM_PROJECT_ID', ''),
            'credentials_path' => env('FCM_CREDENTIALS_PATH', ''),
            'timeout_seconds' => (int) env('FCM_TIMEOUT_SECONDS', 10),
            'retry_times' => (int) env('FCM_RETRY_TIMES', 2),
            'retry_sleep_ms' => (int) env('FCM_RETRY_SLEEP_MS', 200),
        ],
        // Получатели, которым фейковый провайдер детерминированно отказывает
        // (FR-NTF-477): так проверяется цепочка push → telegram → email без
        // реальной недоступности push (ЧТЗ 12.2, п. 1).
        'fake_deterministic_fail_recipients' => array_map(
            static fn (string $value): int => (int) trim($value),
            array_values(array_filter(
                explode(',', (string) env('NOTIFICATIONS_PUSH_FAKE_FAIL_RECIPIENTS', '')),
                static fn (string $value): bool => trim($value) !== '',
            )),
        ),
    ],
];
