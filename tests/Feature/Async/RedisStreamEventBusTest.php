<?php

declare(strict_types=1);

use App\Domain\Events\EventEnvelope;
use App\Domain\Events\EventPayload;
use App\Domain\Events\EventType;
use App\Services\Events\EventStreamRouter;
use App\Services\Events\RedisStreamEventBus;
use Illuminate\Redis\Connections\PredisConnection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Predis\Client as PredisClient;

/**
 * Уникальный префикс потоков: тест не задевает данные других окружений на том же Redis.
 */
function redisStreamPrefix(): string
{
    static $prefix = null;

    return $prefix ??= 'testing:'.Str::lower(Str::random(8)).':';
}

/**
 * Адаптер Redis Streams с тестовым префиксом потоков.
 */
function redisStreamBus(int $pendingIdleSeconds = 60): RedisStreamEventBus
{
    return new RedisStreamEventBus(
        redis: Redis::connection(),
        stream: 'events.orders',
        prefix: redisStreamPrefix(),
        pendingIdleSeconds: $pendingIdleSeconds,
    );
}

/**
 * Конверт события заказа с обязательными полями payload реестра M7.
 */
function redisStreamEnvelope(string $messageId = 'msg-1'): EventEnvelope
{
    return new EventEnvelope(
        messageId: $messageId,
        eventType: EventType::OrderCreated,
        aggregateId: 1,
        occurredAt: new DateTimeImmutable('2026-05-01T12:00:00+00:00'),
        payload: EventPayload::fromArray([
            'event_id' => $messageId,
            'order_id' => 1,
            'tracking_code' => 'TRK-2026-000001',
            'sender_id' => 1,
            'status' => 'created',
            'occurred_at' => '2026-05-01T12:00:00+00:00',
            'locale' => 'ru',
            'pickup_district' => 'Северный',
            'transport' => 'light',
            'price_amount' => 19900,
            'price_currency' => 'RUB',
        ]),
    );
}

/**
 * Конверт события смены контактов: единственный поток с персональными данными.
 */
function redisStreamContactEnvelope(string $messageId = 'msg-contact-1'): EventEnvelope
{
    return new EventEnvelope(
        messageId: $messageId,
        eventType: EventType::UserContactChanged,
        aggregateId: 7,
        occurredAt: new DateTimeImmutable('2026-05-01T12:00:00+00:00'),
        payload: EventPayload::fromArray([
            'event_id' => $messageId,
            'user_id' => 7,
            'email' => 'secret.sender@example.test',
            'locale' => 'ru',
            'occurred_at' => '2026-05-01T12:00:00+00:00',
        ]),
        correlationId: 'req-0123456789abcdef',
    );
}

/**
 * Живой Redis нужен только этим тестам: без него они пропускаются.
 */
function redisStreamsAvailable(): bool
{
    try {
        Redis::connection()->command('executeRaw', [['PING']]);

        return true;
    } catch (Throwable) {
        return false;
    }
}

beforeEach(function (): void {
    if (! redisStreamsAvailable()) {
        $this->markTestSkipped('Redis недоступен: тест адаптера пропущен.');
    }
});

afterEach(function (): void {
    foreach (['events.orders', 'events.notifications', 'events.contacts'] as $stream) {
        try {
            Redis::connection()->command('executeRaw', [['DEL', redisStreamPrefix().$stream]]);
        } catch (Throwable) {
            // Очистка не должна влиять на результат теста.
        }
    }
});

it('публикует конверт события в поток с полями контракта', function (): void {
    redisStreamBus()->publish(redisStreamEnvelope('msg-1'));

    $entries = Redis::connection()->command('executeRaw', [
        ['XRANGE', redisStreamPrefix().'events.orders', '-', '+'],
    ]);

    expect($entries)->toBeArray();

    /** @var list<array{0: string, 1: list<string>}> $entries */
    $fields = $entries[0][1];

    expect($fields)->toContain('message_id')
        ->and($fields)->toContain('event_type')
        ->and($fields)->toContain('schema_version')
        ->and($fields)->toContain('payload')
        ->and($fields)->toContain('msg-1')
        ->and($fields)->toContain('order.created');
});

it('доставляет событие потребителю группы и подтверждает после обработки', function (): void {
    $bus = redisStreamBus();
    $bus->publish(redisStreamEnvelope('msg-1'));

    $received = [];

    $result = $bus->consume(
        'events.orders',
        'notifications-service',
        'consumer-1',
        10,
        function (EventEnvelope $event, string $messageId) use (&$received): void {
            $received[] = [$messageId, $event->messageId, $event->eventType->value];
        },
    );

    expect($result->read)->toBe(1)
        ->and($result->processed)->toBe(1)
        ->and($result->acknowledged)->toBe(1)
        ->and($result->failed)->toBe(0)
        ->and($result->invalid)->toBe(0)
        ->and($result->degraded)->toBeFalse()
        ->and($received)->toHaveCount(1)
        ->and($received[0][1])->toBe('msg-1')
        ->and($received[0][2])->toBe('order.created');
});

it('не выдаёт подтверждённое сообщение повторно', function (): void {
    $bus = redisStreamBus();
    $bus->publish(redisStreamEnvelope('msg-1'));

    $first = $bus->consume('events.orders', 'notifications-service', 'consumer-1', 10, fn () => null);
    $second = $bus->consume('events.orders', 'notifications-service', 'consumer-1', 10, fn () => null);

    expect($first->acknowledged)->toBe(1)
        ->and($second->read)->toBe(0)
        ->and($second->acknowledged)->toBe(0);
});

it('возвращает неподтверждённое сообщение после pending_idle_seconds', function (): void {
    $bus = redisStreamBus(0);
    $bus->publish(redisStreamEnvelope('msg-1'));

    $failed = $bus->consume('events.orders', 'notifications-service', 'consumer-1', 10, function (): void {
        throw new RuntimeException('Сбой обработчика.');
    });

    expect($failed->failed)->toBe(1)
        ->and($failed->acknowledged)->toBe(0);

    $delivered = [];

    $again = $bus->consume(
        'events.orders',
        'notifications-service',
        'consumer-2',
        10,
        function (EventEnvelope $event, string $messageId) use (&$delivered): void {
            $delivered[] = $messageId;
        },
    );

    expect($again->read)->toBe(1)
        ->and($again->acknowledged)->toBe(1)
        ->and($delivered)->toHaveCount(1);
});

it('создаёт consumer group идемпотентно', function (): void {
    $bus = redisStreamBus();

    $first = $bus->consume('events.orders', 'notifications-service', 'consumer-1', 10, fn () => null);
    $second = $bus->consume('events.orders', 'notifications-service', 'consumer-2', 10, fn () => null);

    $groups = (array) Redis::connection()->command('executeRaw', [
        ['XINFO', 'GROUPS', redisStreamPrefix().'events.orders'],
    ]);

    expect($first->degraded)->toBeFalse()
        ->and($second->degraded)->toBeFalse()
        ->and($groups)->toHaveCount(1);
});

it('помечает неизвестный контракт и подтверждает сообщение', function (): void {
    $key = redisStreamPrefix().'events.orders';

    Redis::connection()->command('executeRaw', [[
        'XADD', $key, '*', 'message_id', 'msg-broken', 'event_type', 'order.unknown', 'schema_version', 'v1',
    ]]);

    $result = redisStreamBus()->consume('events.orders', 'notifications-service', 'consumer-1', 10, fn () => null);

    expect($result->read)->toBe(1)
        ->and($result->invalid)->toBe(1)
        ->and($result->processed)->toBe(0)
        ->and($result->acknowledged)->toBe(1)
        ->and($result->degraded)->toBeFalse();
});

it('не роняет процесс при недоступности Redis', function (): void {
    $connection = new PredisConnection(new PredisClient([
        'scheme' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 1,
        'timeout' => 0.2,
    ]));

    $bus = new RedisStreamEventBus($connection, 'events.orders', 'testing:dead:');

    $result = $bus->consume('events.orders', 'notifications-service', 'consumer-1', 10, fn () => null);

    expect($result->degraded)->toBeTrue()
        ->and($result->read)->toBe(0);
});

it('пробрасывает ошибку публикации, чтобы публикатор повторил передачу', function (): void {
    $connection = new PredisConnection(new PredisClient([
        'scheme' => 'tcp',
        'host' => '127.0.0.1',
        'port' => 1,
        'timeout' => 0.2,
    ]));

    $bus = new RedisStreamEventBus($connection, 'events.orders', 'testing:dead:');

    expect(fn () => $bus->publish(redisStreamEnvelope('msg-1')))->toThrow(Exception::class);
});

it('публикует событие контактов в свой поток по типу события', function (): void {
    $bus = new RedisStreamEventBus(
        redis: Redis::connection(),
        stream: 'events.orders',
        prefix: redisStreamPrefix(),
        router: new EventStreamRouter(
            orders: 'events.orders',
            notifications: 'events.notifications',
            contacts: 'events.contacts',
        ),
    );

    $bus->publish(redisStreamContactEnvelope());

    $orders = Redis::connection()->command('executeRaw', [['XLEN', redisStreamPrefix().'events.orders']]);
    $contacts = Redis::connection()->command('executeRaw', [['XLEN', redisStreamPrefix().'events.contacts']]);

    // Персональные данные не смешиваются с потоком заказов (FR-NTF-231, FR-NTF-232).
    expect((int) $contacts)->toBe(1)
        ->and((int) $orders)->toBe(0);
});
