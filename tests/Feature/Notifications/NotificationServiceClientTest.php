<?php

declare(strict_types=1);

use App\Http\Middleware\RequestId;
use App\Services\Notifications\Service\NotificationServiceClient;
use App\Services\Notifications\Service\NotificationServiceUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| Прокси-клиент сервиса оповещений (ЧТЗ 9.4, пункт 4)
|--------------------------------------------------------------------------
|
| FR-OPS-401…406: клиент монолита читает и сохраняет предпочтения, выпускает
| ссылку привязки, отвязывает канал и читает статусы без адресов. Адрес,
| токен, таймаут и повторы берутся из `notifications.service`; отказ сервиса
| возвращается доменным результатом с кодом, `5xx` и отсутствие связи дают
| `NotificationServiceUnavailable`. Сеть не используется: `Http::fake()`.
|
*/

/**
 * @param array<string, mixed> $overrides
 */
function notificationPreferencesClientSettings(array $overrides = []): void
{
    config(array_merge([
        'notifications.service.url' => 'http://notifications.test',
        'notifications.service.token' => 'test-token',
        'notifications.service.timeout_seconds' => 1,
        'notifications.service.retry_times' => 0,
        'notifications.service.retry_sleep_milliseconds' => 0,
    ], $overrides));
}

function notificationPreferencesClient(): NotificationServiceClient
{
    return app(NotificationServiceClient::class);
}

it('читает предпочтения получателя по allowlist и передаёт сервисный токен', function (): void {
    notificationPreferencesClientSettings();

    Http::fake([
        'http://notifications.test/api/v1/preferences*' => Http::response([
            'user_id' => 10,
            'preferences' => [
                [
                    'event_type' => 'order.created',
                    'channels' => ['email'],
                    'source' => 'default',
                    'quiet_hours' => null,
                    'locale' => 'ru',
                    'chat_id' => '-100500',
                ],
                [
                    'event_type' => 'order.assigned',
                    'channels' => ['push', 'telegram'],
                    'source' => 'user',
                    'quiet_hours' => ['from' => '22:00', 'to' => '08:00', 'timezone' => 'Europe/Moscow'],
                    'locale' => 'ru',
                    'address' => 'person@example.com',
                ],
            ],
        ]),
    ]);

    $result = notificationPreferencesClient()->preferences(10);

    expect($result->ok)->toBeTrue()
        ->and($result->status)->toBe(200)
        ->and($result->errorCode)->toBeNull()
        ->and($result->data['rows'])->toBe([
            [
                'event_type' => 'order.created',
                'channels' => ['email'],
                'source' => 'default',
                'quiet_hours' => null,
                'locale' => 'ru',
            ],
            [
                'event_type' => 'order.assigned',
                'channels' => ['push', 'telegram'],
                'source' => 'user',
                'quiet_hours' => ['from' => '22:00', 'to' => '08:00', 'timezone' => 'Europe/Moscow'],
                'locale' => 'ru',
            ],
        ]);

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), 'user_id=10')
        && $request->hasHeader('Authorization', 'Bearer test-token')
        && $request->hasHeader(RequestId::HEADER));
});

it('передаёт роль в запрос предпочтений и в сохранение набора', function (): void {
    notificationPreferencesClientSettings();

    Http::fake([
        'http://notifications.test/api/v1/preferences*' => Http::response([
            'user_id' => 10,
            'preferences' => [],
        ]),
    ]);

    notificationPreferencesClient()->preferences(10, 'courier');
    notificationPreferencesClient()->savePreferences(10, [
        ['event_type' => 'order.assigned', 'channels' => ['push'], 'quiet_hours' => null],
    ], 'courier');

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && str_contains($request->url(), 'role=courier'));

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'PATCH'
        && $request['role'] === 'courier');
});

it('передаёт отказ сервиса доменным результатом с кодом', function (): void {
    notificationPreferencesClientSettings();

    Http::fake([
        'http://notifications.test/api/v1/preferences*' => Http::response(
            ['code' => 'PREFERENCE_CRITICAL_CHANNEL_REQUIRED'],
            422,
            ['Content-Type' => 'application/problem+json'],
        ),
    ]);

    $result = notificationPreferencesClient()->savePreferences(10, [
        ['event_type' => 'order.delivered', 'channels' => ['push'], 'quiet_hours' => null],
    ]);

    expect($result->ok)->toBeFalse()
        ->and($result->status)->toBe(422)
        ->and($result->errorCode)->toBe('PREFERENCE_CRITICAL_CHANNEL_REQUIRED');

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'PATCH'
        && $request['user_id'] === 10
        && $request['preferences'][0]['event_type'] === 'order.delivered');
});

it('сохраняет матрицу и тихие часы и отдаёт новое состояние', function (): void {
    notificationPreferencesClientSettings();

    Http::fake([
        'http://notifications.test/api/v1/preferences' => Http::response([
            'user_id' => 10,
            'preferences' => [[
                'event_type' => 'order.assigned',
                'channels' => ['email', 'telegram'],
                'source' => 'user',
                'quiet_hours' => ['from' => '22:00', 'to' => '08:00', 'timezone' => 'Europe/Moscow'],
                'locale' => 'ru',
            ]],
        ]),
    ]);

    $result = notificationPreferencesClient()->savePreferences(10, [
        [
            'event_type' => 'order.assigned',
            'channels' => ['email', 'telegram'],
            'quiet_hours' => ['from' => '22:00', 'to' => '08:00', 'timezone' => 'Europe/Moscow'],
        ],
    ]);

    expect($result->ok)->toBeTrue()
        ->and($result->data['rows'])->toHaveCount(1)
        ->and($result->data['rows'][0]['channels'])->toBe(['email', 'telegram']);
});

it('выпускает ссылку привязки Telegram', function (): void {
    notificationPreferencesClientSettings();

    Http::fake([
        'http://notifications.test/api/v1/channel-bindings/telegram/start' => Http::response([
            'deep_link' => 'https://t.me/kargo_bot?start=token',
            'expires_at' => '2026-11-07T10:15:00Z',
            'status' => 'pending',
        ], 201),
    ]);

    $result = notificationPreferencesClient()->startTelegramBinding(10);

    expect($result->ok)->toBeTrue()
        ->and($result->status)->toBe(201)
        ->and($result->data['deep_link'])->toBe('https://t.me/kargo_bot?start=token')
        ->and($result->data['expires_at'])->toBe('2026-11-07T10:15:00Z')
        ->and($result->data['status'])->toBe('pending');
});

it('отвязывает канал без тела ответа', function (): void {
    notificationPreferencesClientSettings();

    Http::fake([
        'http://notifications.test/api/v1/channel-bindings/telegram' => Http::response(null, 204),
    ]);

    $result = notificationPreferencesClient()->unbindTelegram(10);

    expect($result->ok)->toBeTrue()->and($result->status)->toBe(204);

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'DELETE'
        && $request['user_id'] === 10);
});

it('отдаёт код причины, когда отвязывать нечего', function (): void {
    notificationPreferencesClientSettings();

    Http::fake([
        'http://notifications.test/api/v1/channel-bindings/telegram' => Http::response(
            ['code' => 'TELEGRAM_BINDING_ABSENT'],
            409,
        ),
    ]);

    $result = notificationPreferencesClient()->unbindTelegram(10);

    expect($result->ok)->toBeFalse()->and($result->errorCode)->toBe('TELEGRAM_BINDING_ABSENT');
});

it('читает статус привязки без адресов чата', function (): void {
    notificationPreferencesClientSettings();

    Http::fake([
        'http://notifications.test/api/v1/channel-bindings*' => Http::response([
            'bindings' => [[
                'channel' => 'telegram',
                'status' => 'verified',
                'verified_at' => '2026-11-07T10:02:00Z',
                'revoked_at' => null,
                'chat_id' => '-100500',
            ]],
        ]),
    ]);

    $result = notificationPreferencesClient()->channelBindings(10);

    expect($result->ok)->toBeTrue()
        ->and($result->data['binding'])->toBe([
            'status' => 'verified',
            'verified_at' => '2026-11-07T10:02:00Z',
        ]);
});

it('считает отсутствие привязки пустым статусом', function (): void {
    notificationPreferencesClientSettings();

    Http::fake([
        'http://notifications.test/api/v1/channel-bindings*' => Http::response(['bindings' => []]),
    ]);

    expect(notificationPreferencesClient()->channelBindings(10)->data['binding'])->toBeNull();
});

it('повторяет запрос столько раз, сколько задано конфигурацией', function (): void {
    notificationPreferencesClientSettings(['notifications.service.retry_times' => 2]);

    $attempts = 0;

    Http::fake([
        '*' => static function () use (&$attempts): mixed {
            $attempts++;

            throw new ConnectionException('down');
        },
    ]);

    expect(static fn () => notificationPreferencesClient()->preferences(10))
        ->toThrow(NotificationServiceUnavailable::class);

    expect($attempts)->toBe(2);
});

it('читает снимок метрик из текста Prometheus и передаёт токен', function (): void {
    notificationPreferencesClientSettings();

    $body = "# HELP notifications_sent_total Отправленные уведомления по каналам\n"
        ."# TYPE notifications_sent_total counter\n"
        ."notifications_sent_total{channel=\"email\"} 42\n"
        ."notifications_failed_total{channel=\"push\"} 3\n"
        ."notifications_retried_total{channel=\"push\"} 5\n"
        ."notifications_send_duration_seconds_bucket{channel=\"email\",le=\"+Inf\"} 7\n"
        ."notifications_dlq_depth{channel=\"push\",state=\"open\"} 3\n"
        ."notifications_dlq_depth{channel=\"push\",state=\"replayed\"} 1\n"
        ."notifications_consumer_lag_seconds{stream=\"events.orders\"} 12\n"
        ."notifications_consumer_pending{stream=\"events.orders\"} 4\n"
        ."notifications_outbox_oldest_unpublished_seconds 9\n";

    Http::fake(['http://notifications.test/metrics' => Http::response($body, 200, ['Content-Type' => 'text/plain; version=0.0.4'])]);

    $metrics = notificationPreferencesClient()->metrics();

    expect($metrics['counters'])->toBe([
        ['channel' => 'email', 'metric' => 'sent', 'value' => 42],
        ['channel' => 'push', 'metric' => 'failed', 'value' => 3],
        ['channel' => 'push', 'metric' => 'retried', 'value' => 5],
    ])
        ->and($metrics['dlq_depth'])->toBe([
            ['channel' => 'push', 'state' => 'open', 'value' => 3],
            ['channel' => 'push', 'state' => 'replayed', 'value' => 1],
        ])
        ->and($metrics['consumers'])->toBe([
            ['stream' => 'events.orders', 'lag_seconds' => 12, 'pending' => 4],
        ])
        ->and($metrics['outbox_oldest_unpublished_seconds'])->toBe(9);

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'http://notifications.test/metrics'
        && $request->hasHeader('Authorization'));
});

it('считает пустой ответ метрик пустым снимком, а 5xx — недоступностью', function (): void {
    notificationPreferencesClientSettings();

    Http::fake(['http://notifications.test/metrics' => Http::response('')]);

    expect(notificationPreferencesClient()->metrics())->toBe([
        'counters' => [],
        'dlq_depth' => [],
        'consumers' => [],
        'outbox_oldest_unpublished_seconds' => null,
    ]);
});

it('считает 5xx на /metrics недоступностью сервиса', function (): void {
    notificationPreferencesClientSettings();

    Http::fake(['http://notifications.test/metrics' => Http::response('', 503)]);

    expect(static fn () => notificationPreferencesClient()->metrics())
        ->toThrow(NotificationServiceUnavailable::class);
});

it('считает 5xx и не настроенный адрес недоступностью сервиса', function (): void {
    notificationPreferencesClientSettings();

    Http::fake(['*' => Http::response(['code' => 'INTERNAL'], 503)]);

    expect(static fn () => notificationPreferencesClient()->preferences(10))
        ->toThrow(NotificationServiceUnavailable::class);

    notificationPreferencesClientSettings(['notifications.service.token' => '']);

    expect(static fn () => notificationPreferencesClient()->channelBindings(10))
        ->toThrow(NotificationServiceUnavailable::class);
});
