<?php

declare(strict_types=1);

/*
| FR-NTF-173: значения публикатора и уведомлений переопределяются переменными окружения.
| Тест подменяет $_SERVER (Laravel читает его раньше $_ENV), перечитывает файл конфигурации
| и возвращает окружение на место — без изменения состояния соседних тестов.
*/

/**
 * Читает файл конфигурации с временно подставленными переменными окружения.
 *
 * @param array<string, string> $variables
 * @return array<string, mixed>
 */
function loadConfigWithEnv(string $file, array $variables): array
{
    $previous = [];

    foreach ($variables as $name => $value) {
        $previous[$name] = $_SERVER[$name] ?? null;
        $_SERVER[$name] = $value;
    }

    try {
        /** @var array<string, mixed> $config */
        $config = require config_path($file.'.php');
    } finally {
        foreach ($previous as $name => $value) {
            if ($value === null) {
                unset($_SERVER[$name]);
            } else {
                $_SERVER[$name] = $value;
            }
        }
    }

    return $config;
}

it('читает параметры публикатора из переменных окружения', function (): void {
    $config = loadConfigWithEnv('events', [
        'EVENTS_PUBLISH_INTERVAL_SECONDS' => '11',
        'EVENTS_PUBLISH_BATCH_SIZE' => '42',
        'EVENTS_PUBLISH_MAX_ATTEMPTS' => '7',
        'EVENTS_PUBLISH_BACKOFF_SECONDS' => '1,2,3',
        'EVENTS_PUBLISH_LOCK_TTL_SECONDS' => '17',
        'EVENTS_INBOX_RETENTION_DAYS' => '14',
        'EVENTS_MAX_PUBLISH_LAG_SECONDS' => '11',
        'EVENTS_MAX_UNPROCESSED_COUNT' => '3',
    ]);

    expect($config)->toMatchArray([
        'publish_interval_seconds' => 11,
        'publish_batch_size' => 42,
        'publish_max_attempts' => 7,
        'publish_backoff_seconds' => [1, 2, 3],
        'publish_lock_ttl_seconds' => 17,
        'inbox_retention_days' => 14,
        'max_publish_lag_seconds' => 11,
        'max_unprocessed_count' => 3,
    ]);
});

it('читает параметры админ-страниц и клиента сервиса из окружения', function (): void {
    // Матрица каналов, попытки и сроки хранения принадлежат сервису
    // оповещений: в монолите остаются только прокси-страницы (FR-NTF-403).
    $config = loadConfigWithEnv('notifications', [
        'NOTIFICATIONS_SERVICE_URL' => 'http://service.test',
        'NOTIFICATIONS_SERVICE_TOKEN' => 'secret-token',
        'NOTIFICATIONS_SERVICE_TIMEOUT_SECONDS' => '9',
        'NOTIFICATIONS_SERVICE_RETRY_TIMES' => '3',
        'NOTIFICATIONS_SERVICE_RETRY_SLEEP_MS' => '250',
        'NOTIFICATIONS_ADMIN_PAGE_SIZE' => '50',
    ]);

    expect($config)->toMatchArray([
        'admin' => ['page_size' => 50],
        'service' => [
            'url' => 'http://service.test',
            'token' => 'secret-token',
            'timeout_seconds' => 9,
            'retry_times' => 3,
            'retry_sleep_milliseconds' => 250,
        ],
    ])
        ->and(array_keys($config))->toEqualCanonicalizing(['admin', 'service']);
});

it('читает retry_after очереди из переменных окружения', function (): void {
    $config = loadConfigWithEnv('queue', [
        'REDIS_QUEUE_RETRY_AFTER' => '120',
    ]);

    /** @var array<string, mixed> $connections */
    $connections = $config['connections'];
    /** @var array<string, mixed> $redis */
    $redis = $connections['redis'];

    expect($redis['retry_after'])->toBe(120);
});

it('читает транспорт, потоки и параметры потребления из переменных окружения', function (): void {
    $config = loadConfigWithEnv('events', [
        'EVENTS_TRANSPORT' => 'in-memory',
        'EVENTS_STREAM_PREFIX' => 'testing:',
        'EVENTS_STREAM_ORDERS' => 'custom.orders',
        'EVENTS_STREAM_NOTIFICATIONS' => 'custom.notifications',
        'EVENTS_STREAM_CONTACTS' => 'custom.contacts',
        'EVENTS_CONSUMER_GROUP' => 'custom-group',
        'EVENTS_CONSUMER_NAME' => 'custom-consumer',
        'EVENTS_CONSUME_BATCH_SIZE' => '7',
        'EVENTS_PENDING_IDLE_SECONDS' => '13',
    ]);

    expect($config['transport'])->toBe('in-memory')
        ->and($config['stream_prefix'])->toBe('testing:')
        ->and($config['streams'])->toBe([
            'orders' => 'custom.orders',
            'notifications' => 'custom.notifications',
            'contacts' => 'custom.contacts',
        ])
        ->and($config['consumer_group'])->toBe('custom-group')
        ->and($config['consumer_name'])->toBe('custom-consumer')
        ->and($config['consume_batch_size'])->toBe(7)
        ->and($config['pending_idle_seconds'])->toBe(13);
});
