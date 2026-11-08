<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

/*
|--------------------------------------------------------------------------
| Наблюдаемость и конфигурация сервиса (FR-OPS-204, 205, 211, 213; ЧТЗ 7, п. 8)
|--------------------------------------------------------------------------
|
| Сроки хранения, таймауты и расписание читаются только из конфигурации.
| Guard «retry_after» повторяет проверку монолита: пауза повтора отправки
| строго больше худшего времени обработки пачки, иначе уведомление,
| которое ещё отправляется, взяли бы повторно.
|
*/

it('держит сроки хранения из конфигурации', function (): void {
    expect(config('notifications.retention.days'))->toBeInt()->toBeGreaterThan(0)
        ->and(config('notifications.retention.attempt_days'))->toBeInt()->toBeGreaterThan(0)
        ->and(config('notifications.retention.push_days'))->toBe(30)
        ->and(config('notifications.retention.inbox_days'))->toBe(30)
        ->and(config('notifications.retention.dedup_days'))->toBe(30);
});

it('держит retry_after отправки строго больше времени обработки пачки', function (): void {
    $batchSeconds = (int) config('notifications.sender.batch_size')
        * (int) config('notifications.sender.timeout_seconds');

    expect(config('notifications.sender.timeout_seconds'))->toBeInt()->toBeGreaterThan(0)
        ->and(config('notifications.sender.retry_after_seconds'))->toBeInt()
        ->and($batchSeconds)->toBeGreaterThan(0)
        ->and(config('notifications.sender.retry_after_seconds'))->toBeGreaterThan($batchSeconds);
});

it('держит таймауты отправки, почты, базы и Redis в конфигурации', function (): void {
    expect(config('mail.mailers.smtp.timeout'))->toBeInt()->toBeGreaterThan(0)
        ->and(config('database.connections.pgsql.connect_timeout'))->toBeInt()->toBeGreaterThan(0)
        ->and(config('database.redis.default.timeout'))->toBeFloat()->toBeGreaterThan(0)
        ->and(config('database.redis.default.read_write_timeout'))->toBeFloat()->toBeGreaterThan(0);

    // libpq читает таймаут подключения из окружения процесса: поставщик
    // услуг выставляет его из конфигурации при старте (FR-OPS-205).
    expect((int) getenv('PGCONNECT_TIMEOUT'))->toBe((int) config('database.connections.pgsql.connect_timeout'));
});

it('регистрирует очистку журнала в расписании по cron из конфигурации', function (): void {
    expect(Artisan::call('schedule:list', ['--json' => true]))->toBe(0);

    /** @var list<array<string, mixed>> $events */
    $events = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    $event = collect($events)->first(
        fn (array $event): bool => str_contains((string) ($event['command'] ?? ''), 'notifications:purge'),
    );

    expect($event)->not->toBeNull();

    /** @var array<string, mixed> $event */
    $cron = (string) config('notifications.retention.purge_cron');

    expect($cron)->not->toBeEmpty();

    // Формат schedule:list добавляет часовой пояс к cron-выражению.
    expect((string) $event['expression'])->toContain($cron);
});

it('не задаёт литеральных порогов состояния сервиса', function (): void {
    expect(config('notifications.health.lag_threshold_seconds'))->toBeInt()->toBeGreaterThanOrEqual(0)
        ->and(config('notifications.health.pending_threshold'))->toBeInt()->toBeGreaterThanOrEqual(0);
});

it('пишет лаг и число необработанных в журнал при деградации', function (): void {
    /** @var ArrayObject<int, MessageLogged> $logs */
    $logs = new ArrayObject;

    Event::listen(MessageLogged::class, static function (MessageLogged $record) use ($logs): void {
        $logs->append($record);
    });

    bindFakeRedis([
        'xinfo' => [[
            'name' => (string) config('notifications.transport.consumer_group'),
            'consumers' => 1,
            'pending' => 150,
            'last-delivered-id' => '0-0',
            'entries-read' => null,
            'lag' => 150,
        ]],
    ]);

    $this->getJson('/health')->assertStatus(503);

    $record = collect($logs->getArrayCopy())->first(
        fn (MessageLogged $record): bool => $record->message === 'Состояние сервиса деградировано.',
    );

    expect($record)->not->toBeNull();

    /** @var MessageLogged $record */
    expect($record->context['status'])->toBe('degraded')
        ->and($record->context['pending'])->toBe(150);
});

it('отвечает /health без персональных данных и без числа контактов', function (): void {
    bindFakeRedis([
        'xinfo' => [[
            'name' => (string) config('notifications.transport.consumer_group'),
            'consumers' => 1,
            'pending' => 0,
            'last-delivered-id' => '0-0',
            'entries-read' => null,
            'lag' => 0,
        ]],
    ]);

    $content = (string) $this->getJson('/health')->assertOk()->getContent();

    expect($content)->not->toContain('contacts')
        ->not->toContain('email')
        ->not->toContain('recipient_id')
        ->not->toContain('user_id')
        ->not->toContain('token')
        ->not->toContain('address');
});

/*
|--------------------------------------------------------------------------
| Ключи ЧТЗ 8.5: только окружение и безопасные значения по умолчанию
|--------------------------------------------------------------------------
|
| Каждый ключ блоков `telegram` и `preferences` читается из env, а без
| переменной остаётся безопасное значение из ЧТЗ 8.5 (FR-OPS-421…425).
| Конфигурация собирается повторно из файла, поэтому проверка видит
| именно результат `env()`, а не кэш уже загруженного config.
|
*/

/**
 * Конфигурация сервиса, собранная из текущего окружения процесса.
 *
 * @return array<string, mixed>
 */
function notificationsConfigFromEnv(): array
{
    return require config_path('notifications.php');
}

/**
 * Выполняет обратный вызов со значением переменной окружения и восстанавливает его.
 *
 * @param Closure(): mixed $callback
 */
function notificationsWithEnv(string $variable, ?string $value, Closure $callback): mixed
{
    $previous = getenv($variable);
    $hadServer = array_key_exists($variable, $_SERVER);
    $server = $hadServer ? $_SERVER[$variable] : null;
    $hadEnv = array_key_exists($variable, $_ENV);
    $env = $hadEnv ? $_ENV[$variable] : null;

    try {
        if ($value === null) {
            putenv($variable);
            unset($_SERVER[$variable], $_ENV[$variable]);
        } else {
            putenv($variable.'='.$value);
            $_SERVER[$variable] = $value;
            $_ENV[$variable] = $value;
        }

        return $callback();
    } finally {
        if ($previous === false) {
            putenv($variable);
        } else {
            putenv($variable.'='.$previous);
        }

        if ($hadServer) {
            $_SERVER[$variable] = $server;
        } else {
            unset($_SERVER[$variable]);
        }

        if ($hadEnv) {
            $_ENV[$variable] = $env;
        } else {
            unset($_ENV[$variable]);
        }
    }
}

dataset('ключи конфигурации ЧТЗ 8.5', [
    'enabled_channels' => ['NOTIFICATIONS_ENABLED_CHANNELS', 'enabled_channels', ['email', 'push']],
    'telegram.bot_token' => ['NOTIFICATIONS_TELEGRAM_BOT_TOKEN', 'telegram.bot_token', ''],
    'telegram.bot_username' => ['NOTIFICATIONS_TELEGRAM_BOT_USERNAME', 'telegram.bot_username', ''],
    'telegram.api_url' => ['NOTIFICATIONS_TELEGRAM_API_URL', 'telegram.api_url', 'https://api.telegram.org'],
    'telegram.timeout_seconds' => ['NOTIFICATIONS_TELEGRAM_TIMEOUT_SECONDS', 'telegram.timeout_seconds', 10],
    'telegram.message_limit' => ['NOTIFICATIONS_TELEGRAM_MESSAGE_LIMIT', 'telegram.message_limit', 4096],
    'telegram.retry_after_default_seconds' => ['NOTIFICATIONS_TELEGRAM_RETRY_AFTER_DEFAULT_SECONDS', 'telegram.retry_after_default_seconds', 30],
    'telegram.link_token_ttl_seconds' => ['NOTIFICATIONS_TELEGRAM_LINK_TOKEN_TTL_SECONDS', 'telegram.link_token_ttl_seconds', 900],
    'telegram.link_token_retention_days' => ['NOTIFICATIONS_TELEGRAM_LINK_TOKEN_RETENTION_DAYS', 'telegram.link_token_retention_days', 7],
    'telegram.updates_mode' => ['NOTIFICATIONS_TELEGRAM_UPDATES_MODE', 'telegram.updates_mode', 'polling'],
    'telegram.poll_timeout_seconds' => ['NOTIFICATIONS_TELEGRAM_POLL_TIMEOUT_SECONDS', 'telegram.poll_timeout_seconds', 25],
    'telegram.poll_batch_size' => ['NOTIFICATIONS_TELEGRAM_POLL_BATCH_SIZE', 'telegram.poll_batch_size', 50],
    'telegram.webhook_secret' => ['NOTIFICATIONS_TELEGRAM_WEBHOOK_SECRET', 'telegram.webhook_secret', ''],
    'preferences.default_timezone' => ['NOTIFICATIONS_PREFERENCES_DEFAULT_TIMEZONE', 'preferences.default_timezone', 'Europe/Moscow'],
    'preferences.channel_priority' => ['NOTIFICATIONS_PREFERENCES_CHANNEL_PRIORITY', 'preferences.channel_priority', ['push', 'telegram', 'email']],
    'preferences.max_fallback_depth' => ['NOTIFICATIONS_PREFERENCES_MAX_FALLBACK_DEPTH', 'preferences.max_fallback_depth', 3],
    'preferences.critical_event_types' => ['NOTIFICATIONS_CRITICAL_EVENT_TYPES', 'preferences.critical_event_types', ['order.delivered', 'order.failed']],
    'preferences.quiet_hours_channels' => ['NOTIFICATIONS_QUIET_HOURS_CHANNELS', 'preferences.quiet_hours_channels', ['push', 'telegram']],
    'preferences.sender_event_types' => [
        'NOTIFICATIONS_SENDER_EVENT_TYPES',
        'preferences.sender_event_types',
        ['order.created', 'order.assigned', 'order.picked_up', 'order.delivered', 'order.failed', 'order.cancelled'],
    ],
    'preferences.courier_event_types' => ['NOTIFICATIONS_COURIER_EVENT_TYPES', 'preferences.courier_event_types', ['order.assigned', 'order.cancelled']],
]);

it('читает ключ ЧТЗ 8.5 из окружения и держит безопасное значение по умолчанию', function (string $variable, string $path, mixed $default): void {
    $fromDefaults = notificationsWithEnv($variable, null, notificationsConfigFromEnv(...));

    expect(data_get($fromDefaults, $path))->toBe($default);

    $custom = match (true) {
        is_int($default) => '42',
        is_array($default) => 'first,second',
        default => 'test-value',
    };

    $expected = match (true) {
        is_int($default) => 42,
        is_array($default) => ['first', 'second'],
        default => 'test-value',
    };

    $fromEnv = notificationsWithEnv($variable, $custom, notificationsConfigFromEnv(...));

    expect(data_get($fromEnv, $path))->toBe($expected);
})->with('ключи конфигурации ЧТЗ 8.5');

it('не включает Telegram в каналы до настройки токена', function (): void {
    $settings = notificationsWithEnv('NOTIFICATIONS_ENABLED_CHANNELS', null, notificationsConfigFromEnv(...));

    expect($settings['enabled_channels'])->toBe(['email', 'push'])
        ->and(in_array('telegram', (array) $settings['enabled_channels'], true))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| M13: ключи DLQ, метрик, токенов, FCM и брокера (ЧТЗ 8.5, 9.6 п. 3)
|--------------------------------------------------------------------------
|
| Каждый новый ключ читается из env и без переменной остаётся безопасным
| значением по умолчанию. Перечень ключей фиксирован: удаление ключа из
| конфигурации ломает этот набор (FR-OPS-508, FR-NTF-549).
|
*/

dataset('целочисленные ключи конфигурации M13', [
    'dlq.retention_days' => ['NOTIFICATIONS_DLQ_RETENTION_DAYS', 'dlq.retention_days', 90],
    'dlq.page_limit' => ['NOTIFICATIONS_DLQ_PAGE_LIMIT', 'dlq.page_limit', 50],
    'dlq.max_page_limit' => ['NOTIFICATIONS_DLQ_MAX_PAGE_LIMIT', 'dlq.max_page_limit', 200],
    'device_tokens.retention_days' => ['NOTIFICATIONS_DEVICE_TOKEN_RETENTION_DAYS', 'device_tokens.retention_days', 30],
    'metrics.snapshot_ttl_seconds' => ['NOTIFICATIONS_METRICS_SNAPSHOT_TTL_SECONDS', 'metrics.snapshot_ttl_seconds', 30],
    'push.fcm.timeout_seconds' => ['FCM_TIMEOUT_SECONDS', 'push.fcm.timeout_seconds', 10],
    'push.fcm.retry_times' => ['FCM_RETRY_TIMES', 'push.fcm.retry_times', 2],
    'push.fcm.retry_sleep_ms' => ['FCM_RETRY_SLEEP_MS', 'push.fcm.retry_sleep_ms', 200],
    'transport.rabbitmq.port' => ['RABBITMQ_PORT', 'transport.rabbitmq.port', 5672],
    'transport.rabbitmq.heartbeat' => ['RABBITMQ_HEARTBEAT', 'transport.rabbitmq.heartbeat', 30],
    'transport.rabbitmq.prefetch' => ['RABBITMQ_PREFETCH', 'transport.rabbitmq.prefetch', 50],
    'transport.rabbitmq.message_ttl_seconds' => ['RABBITMQ_MESSAGE_TTL_SECONDS', 'transport.rabbitmq.message_ttl_seconds', 300],
    'transport.rabbitmq.max_attempts' => ['RABBITMQ_MAX_ATTEMPTS', 'transport.rabbitmq.max_attempts', 5],
]);

it('читает целочисленный ключ M13 из окружения и держит значение по умолчанию', function (string $variable, string $path, int $default): void {
    $fromDefaults = notificationsWithEnv($variable, null, notificationsConfigFromEnv(...));

    expect(data_get($fromDefaults, $path))->toBe($default);

    $fromEnv = notificationsWithEnv($variable, '42', notificationsConfigFromEnv(...));

    expect(data_get($fromEnv, $path))->toBe(42);
})->with('целочисленные ключи конфигурации M13');

dataset('строковые ключи конфигурации M13', [
    'push.fcm.project_id' => ['FCM_PROJECT_ID', 'push.fcm.project_id', ''],
    'push.fcm.credentials_path' => ['FCM_CREDENTIALS_PATH', 'push.fcm.credentials_path', ''],
    'transport.rabbitmq.host' => ['RABBITMQ_HOST', 'transport.rabbitmq.host', 'rabbitmq'],
    'transport.rabbitmq.user' => ['RABBITMQ_USER', 'transport.rabbitmq.user', 'guest'],
    'transport.rabbitmq.password' => ['RABBITMQ_PASSWORD', 'transport.rabbitmq.password', 'guest'],
    'transport.rabbitmq.vhost' => ['RABBITMQ_VHOST', 'transport.rabbitmq.vhost', '/'],
    'transport.rabbitmq.exchange' => ['RABBITMQ_EXCHANGE', 'transport.rabbitmq.exchange', 'events'],
    'transport.rabbitmq.queue' => ['RABBITMQ_QUEUE', 'transport.rabbitmq.queue', 'notifications.events'],
    'transport.rabbitmq.dlx_exchange' => ['RABBITMQ_DLX_EXCHANGE', 'transport.rabbitmq.dlx_exchange', 'events.dlx'],
    'transport.rabbitmq.dlq_queue' => ['RABBITMQ_DLQ_QUEUE', 'transport.rabbitmq.dlq_queue', 'notifications.dlq'],
]);

it('читает строковый ключ M13 из окружения и держит значение по умолчанию', function (string $variable, string $path, string $default): void {
    $fromDefaults = notificationsWithEnv($variable, null, notificationsConfigFromEnv(...));

    expect(data_get($fromDefaults, $path))->toBe($default);

    $fromEnv = notificationsWithEnv($variable, 'test-value', notificationsConfigFromEnv(...));

    expect(data_get($fromEnv, $path))->toBe('test-value');
})->with('строковые ключи конфигурации M13');

it('читает включение метрик из окружения и по умолчанию держит true', function (): void {
    $fromDefaults = notificationsWithEnv('NOTIFICATIONS_METRICS_ENABLED', null, notificationsConfigFromEnv(...));

    expect(data_get($fromDefaults, 'metrics.enabled'))->toBeTrue();

    $fromEnv = notificationsWithEnv('NOTIFICATIONS_METRICS_ENABLED', 'false', notificationsConfigFromEnv(...));

    expect(data_get($fromEnv, 'metrics.enabled'))->toBeFalse();
});

it('читает границы гистограммы метрик из окружения и держит ряд по умолчанию', function (): void {
    $fromDefaults = notificationsWithEnv('NOTIFICATIONS_METRICS_HISTOGRAM_BUCKETS', null, notificationsConfigFromEnv(...));

    expect(data_get($fromDefaults, 'metrics.histogram_buckets'))->toBe([0.1, 0.5, 1.0, 2.0, 5.0, 10.0]);

    $fromEnv = notificationsWithEnv('NOTIFICATIONS_METRICS_HISTOGRAM_BUCKETS', '0.25,1,3', notificationsConfigFromEnv(...));

    expect(data_get($fromEnv, 'metrics.histogram_buckets'))->toBe([0.25, 1.0, 3.0]);
});

it('регистрирует поллер отправки и очистку токенов привязки по одному разу', function (): void {
    expect(Artisan::call('schedule:list', ['--json' => true]))->toBe(0);

    /** @var list<array<string, mixed>> $events */
    $events = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    $commands = array_map(
        static fn (array $event): string => (string) ($event['command'] ?? ''),
        $events,
    );

    $send = array_values(array_filter($commands, static fn (string $command): bool => str_contains($command, 'notifications:send')));
    $purge = array_values(array_filter($commands, static fn (string $command): bool => str_contains($command, 'notifications:telegram:purge')));
    $poll = array_values(array_filter($commands, static fn (string $command): bool => str_contains($command, 'notifications:telegram:poll')));

    // Один процесс на роль: поллер отправки в расписании один, поллер
    // апдейтов — отдельный контейнер и в расписании не дублируется (FR-OPS-423).
    expect($send)->toHaveCount(1)
        ->and($purge)->toHaveCount(1)
        ->and($poll)->toBe([]);
});
