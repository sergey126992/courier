<?php

declare(strict_types=1);

use App\Domain\Notifications\DlqState;
use App\Domain\Notifications\NotificationErrorCode;
use App\Services\Events\TransportDriver;
use App\Services\Notifications\Channels\FakePushProvider;
use App\Services\Notifications\Channels\PushProviderFactory;
use Illuminate\Foundation\Application;
use InvalidArgumentException;

it('поднимает приложение сервиса оповещений', function (): void {
    expect(app())->toBeInstanceOf(Application::class)
        ->and(app()->bound('config'))->toBeTrue();
});

it('читает параметры транспорта из конфигурации', function (): void {
    expect(config('notifications.transport.driver'))->toBe('redis')
        ->and(config('notifications.transport.consumer_group'))->toBe('notifications-service-test')
        ->and(config('notifications.transport.streams.orders'))->toBe('events.orders.test');
});

it('читает параметры контрактов, отправки и повторов из конфигурации', function (): void {
    expect(config('notifications.contracts.path'))->toBeString()->not->toBeEmpty()
        ->and(config('notifications.contracts.schema_version'))->toBeString()->not->toBeEmpty()
        ->and(config('notifications.sender.max_attempts'))->toBeGreaterThan(0)
        ->and(config('notifications.sender.backoff_seconds'))->toBeArray()->not->toBeEmpty()
        ->and(config('notifications.sender.poll_interval_seconds'))->toBeGreaterThan(0);
});

it('имеет непустой сервисный токен по умолчанию', function (): void {
    expect(config('notifications.api.token'))->toBeString()->not->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| M13: каркас конфигурации DLQ, метрик, токенов, FCM и брокера (ЧТЗ 8.5)
|--------------------------------------------------------------------------
|
| Все значения читаются только из окружения (FR-OPS-508, FR-NTF-549):
| литералов сроков, лимитов и имён очередей в коде нет.
|
*/

it('держит сроки и лимиты DLQ по умолчанию', function (): void {
    expect(config('notifications.dlq.retention_days'))->toBe(90)
        ->and(config('notifications.dlq.page_limit'))->toBe(50)
        ->and(config('notifications.dlq.max_page_limit'))->toBe(200);
});

it('держит срок хранения отозванных токенов устройств', function (): void {
    expect(config('notifications.device_tokens.retention_days'))->toBe(30);
});

it('держит параметры метрик по умолчанию', function (): void {
    expect(config('notifications.metrics.enabled'))->toBeTrue()
        ->and(config('notifications.metrics.snapshot_ttl_seconds'))->toBe(30)
        ->and(config('notifications.metrics.histogram_buckets'))->toBe([0.1, 0.5, 1.0, 2.0, 5.0, 10.0]);
});

it('держит параметры FCM и брокера по умолчанию', function (): void {
    expect(config('notifications.push.fcm.project_id'))->toBeString()
        ->and(config('notifications.push.fcm.credentials_path'))->toBeString()
        ->and(config('notifications.push.fcm.timeout_seconds'))->toBe(10)
        ->and(config('notifications.push.fcm.retry_times'))->toBe(2)
        ->and(config('notifications.push.fcm.retry_sleep_ms'))->toBe(200)
        ->and(config('notifications.transport.rabbitmq.port'))->toBe(5672)
        ->and(config('notifications.transport.rabbitmq.heartbeat'))->toBe(30)
        ->and(config('notifications.transport.rabbitmq.prefetch'))->toBe(50)
        ->and(config('notifications.transport.rabbitmq.timeout'))->toBe(5)
        ->and(config('notifications.transport.rabbitmq.routing'))->toBe([
            'order' => 'orders',
            'courier' => 'orders',
            'user' => 'contacts',
            'notification' => 'notifications',
        ])
        ->and(config('notifications.transport.rabbitmq.message_ttl_seconds'))->toBe(300)
        ->and(config('notifications.transport.rabbitmq.max_attempts'))->toBe(5)
        ->and(config('notifications.transport.rabbitmq.exchange'))->toBeString()->not->toBeEmpty()
        ->and(config('notifications.transport.rabbitmq.queue'))->toBeString()->not->toBeEmpty()
        ->and(config('notifications.transport.rabbitmq.dlx_exchange'))->toBeString()->not->toBeEmpty()
        ->and(config('notifications.transport.rabbitmq.dlq_queue'))->toBeString()->not->toBeEmpty();
});

it('принимает только известный транспорт событий', function (): void {
    expect(TransportDriver::supported())->toBe([TransportDriver::REDIS, TransportDriver::RABBITMQ])
        ->and(TransportDriver::resolve('redis'))->toBe('redis')
        ->and(TransportDriver::resolve(' REDIS '))->toBe('redis');

    // Значение из конфигурации по умолчанию проходит проверку (FR-NTF-545).
    expect(TransportDriver::resolve((string) config('notifications.transport.driver')))->toBe('redis');

    // Адаптер брокера подключается значением `rabbitmq` (FR-NTF-541).
    expect(TransportDriver::resolve('rabbitmq'))->toBe('rabbitmq')
        ->and(TransportDriver::resolve(' RABBITMQ '))->toBe('rabbitmq');

    // Неизвестное значение останавливает процесс ошибкой конфигурации,
    // а не тихим выбором транспорта (FR-NTF-545).
    expect(fn (): string => TransportDriver::resolve('amqp'))->toThrow(InvalidArgumentException::class)
        ->and(fn (): string => TransportDriver::resolve(''))->toThrow(InvalidArgumentException::class);
});

it('останавливает отправку при неизвестном push-провайдере', function (): void {
    $previous = config('notifications.push.provider');

    try {
        // Неизвестное значение — ошибка конфигурации, а не тихий выбор
        // провайдера (FR-NTF-524, ЧТЗ 9.6 п. 2).
        config(['notifications.push.provider' => 'apns']);

        expect(fn (): object => (new PushProviderFactory)->make())->toThrow(InvalidArgumentException::class);

        config(['notifications.push.provider' => 'fake']);

        expect((new PushProviderFactory)->make())->toBeInstanceOf(FakePushProvider::class);
    } finally {
        config(['notifications.push.provider' => $previous]);
    }
});

it('знает состояния записи DLQ и их переходы', function (): void {
    expect(array_map(static fn (DlqState $state): string => $state->value, DlqState::cases()))
        ->toBe(['open', 'replayed', 'resolved'])
        ->and(DlqState::Open->isTerminal())->toBeFalse()
        ->and(DlqState::Replayed->isTerminal())->toBeFalse()
        ->and(DlqState::Resolved->isTerminal())->toBeTrue()
        ->and(DlqState::Open->canReplay())->toBeTrue()
        ->and(DlqState::Replayed->canReplay())->toBeFalse()
        ->and(DlqState::Resolved->canReplay())->toBeFalse()
        ->and(DlqState::Open->isUnresolved())->toBeTrue()
        ->and(DlqState::Replayed->isUnresolved())->toBeTrue()
        ->and(DlqState::Resolved->isUnresolved())->toBeFalse();
});

it('содержит коды ошибок M13 без персональных данных', function (): void {
    expect(NotificationErrorCode::NOTIFICATION_DEVICE_UNREGISTERED)->toBe('NOTIFICATION_DEVICE_UNREGISTERED')
        ->and(NotificationErrorCode::NOTIFICATION_PUSH_TOKEN_MISSING)->toBe('NOTIFICATION_PUSH_TOKEN_MISSING')
        ->and(NotificationErrorCode::PUSH_PROVIDER_UNAVAILABLE)->toBe('PUSH_PROVIDER_UNAVAILABLE')
        ->and(NotificationErrorCode::PUSH_PROVIDER_RATE_LIMITED)->toBe('PUSH_PROVIDER_RATE_LIMITED')
        ->and(NotificationErrorCode::PUSH_PROVIDER_REJECTED)->toBe('PUSH_PROVIDER_REJECTED')
        ->and(NotificationErrorCode::DLQ_ENTRY_NOT_FOUND)->toBe('DLQ_ENTRY_NOT_FOUND')
        ->and(NotificationErrorCode::DLQ_ENTRY_ALREADY_RESOLVED)->toBe('DLQ_ENTRY_ALREADY_RESOLVED')
        ->and(NotificationErrorCode::BROKER_UNAVAILABLE)->toBe('BROKER_UNAVAILABLE')
        ->and(NotificationErrorCode::METRICS_UNAVAILABLE)->toBe('METRICS_UNAVAILABLE')
        ->and(NotificationErrorCode::DEVICE_TOKEN_INVALID)->toBe('DEVICE_TOKEN_INVALID')
        ->and(NotificationErrorCode::DEVICE_TOKEN_UNKNOWN)->toBe('DEVICE_TOKEN_UNKNOWN');
});
