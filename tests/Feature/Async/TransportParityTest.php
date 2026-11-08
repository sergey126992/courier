<?php

declare(strict_types=1);

use App\Domain\Events\EventEnvelope;
use App\Domain\Events\EventPayload;
use App\Domain\Events\EventSchemaVersion;
use App\Domain\Events\EventType;
use App\Services\Events\Amqp\PhpAmqpBroker;
use App\Services\Events\Contracts\EventBus;
use App\Services\Events\RabbitMqEventBus;
use App\Services\Events\RabbitMqTopology;
use App\Services\Events\RedisStreamEventBus;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\FakeRedisConnection;

/*
|--------------------------------------------------------------------------
| Паритет транспортов монолита (FR-NTF-547, ЧТЗ 9.4)
|--------------------------------------------------------------------------
|
| Один набор кейсов для Redis Streams и RabbitMQ: публикация → потребление →
| ack → reclaim → повтор. Redis доступен всегда; RabbitMQ — при профиле
| `broker`, иначе кейс пропускается. Домен и Actions один и тот же — меняется
| только конфигурация транспорта.
|
*/

/**
 * Конверт события заказа для паритетного кейса.
 */
function monolithParityEnvelope(string $messageId = 'msg-parity-1'): EventEnvelope
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
        schemaVersion: EventSchemaVersion::V1,
        correlationId: 'corr-parity-1',
    );
}

/**
 * Шина и ключ потока для паритетного кейса.
 *
 * @return array{bus: EventBus, stream: string, group: string, suffix: string, broker: PhpAmqpBroker|null}
 */
function monolithParityBus(string $transport): array
{
    $suffix = Str::lower(Str::random(8));

    if ($transport === 'redis') {
        $prefix = 'testing:parity:'.$suffix.':';

        return [
            'bus' => new RedisStreamEventBus(Redis::connection(), 'events.orders', $prefix, 0),
            'stream' => 'events.orders',
            'group' => 'parity-'.$suffix,
            'suffix' => $suffix,
            'broker' => null,
            'key' => $prefix.'events.orders',
        ];
    }

    $config = [
        'exchange' => 'parity.events.'.$suffix,
        'dlx_exchange' => 'parity.events.dlx.'.$suffix,
        'message_ttl_seconds' => 60,
        'max_attempts' => 5,
    ];

    $broker = new PhpAmqpBroker([
        'host' => (string) config('events.rabbitmq.host'),
        'port' => (int) config('events.rabbitmq.port'),
        'user' => (string) config('events.rabbitmq.user'),
        'password' => (string) config('events.rabbitmq.password'),
        'vhost' => (string) config('events.rabbitmq.vhost'),
        'heartbeat' => (int) config('events.rabbitmq.heartbeat'),
        'timeout' => 2,
        'prefetch' => 10,
    ]);

    $topology = new RabbitMqTopology($config, ['order' => 'orders'], ['events.orders']);

    return [
        'bus' => new RabbitMqEventBus($broker, $topology, 0),
        'stream' => 'events.orders',
        'group' => 'parity-'.$suffix,
        'suffix' => $suffix,
        'broker' => $broker,
        'key' => null,
    ];
}

/**
 * Доступен ли транспорт кейса: RabbitMQ требует профиля `broker`.
 *
 * @param array<string, mixed> $subject
 */
function monolithParityBrokerAvailable(string $transport, array $subject): bool
{
    return $transport !== 'rabbitmq' || $subject['broker']->isConnected();
}

afterEach(function (): void {
    $subject = $GLOBALS['monolithParity'] ?? null;

    if (! is_array($subject)) {
        return;
    }

    if ($subject['broker'] instanceof PhpAmqpBroker) {
        $subject['broker']->close();
    } else {
        Redis::connection()->command('executeRaw', [['DEL', $subject['key']]]);
    }

    $GLOBALS['monolithParity'] = null;
});

it('публикует, потребляет и подтверждает событие', function (string $transport): void {
    $subject = monolithParityBus($transport);
    $GLOBALS['monolithParity'] = $subject;
    if (! monolithParityBrokerAvailable($transport, $subject)) {
        $this->markTestSkipped('Брокер RabbitMQ недоступен: запустите docker compose --profile broker.');
    }

    $handled = [];

    $subject['bus']->consume(
        $subject['stream'],
        $subject['group'],
        'consumer-1',
        10,
        static function (): null {
            return null;
        },
    );

    $subject['bus']->publish(monolithParityEnvelope());

    $result = $subject['bus']->consume(
        $subject['stream'],
        $subject['group'],
        'consumer-1',
        10,
        static function (EventEnvelope $event) use (&$handled): void {
            $handled[] = $event->messageId;
        },
    );

    expect($result->read)->toBe(1)
        ->and($result->processed)->toBe(1)
        ->and($result->acknowledged)->toBe(1)
        ->and($handled)->toBe(['msg-parity-1']);

    $again = $subject['bus']->reclaim($subject['stream'], $subject['group'], 'consumer-1', 0, 10, static fn (): null => null);

    expect($again->read)->toBe(0);
})->with(['redis', 'rabbitmq']);

it('возвращает событие в обработку после неудачного обработчика', function (string $transport): void {
    $subject = monolithParityBus($transport);
    $GLOBALS['monolithParity'] = $subject;
    if (! monolithParityBrokerAvailable($transport, $subject)) {
        $this->markTestSkipped('Брокер RabbitMQ недоступен: запустите docker compose --profile broker.');
    }

    $subject['bus']->consume($subject['stream'], $subject['group'], 'consumer-1', 10, static fn (): null => null);
    $subject['bus']->publish(monolithParityEnvelope());

    $failed = $subject['bus']->consume(
        $subject['stream'],
        $subject['group'],
        'consumer-1',
        10,
        static function (): void {
            throw new RuntimeException('effect failed');
        },
    );

    expect($failed->failed)->toBe(1);

    $handled = [];

    $retried = $subject['bus']->consume(
        $subject['stream'],
        $subject['group'],
        'consumer-1',
        10,
        static function (EventEnvelope $event) use (&$handled): void {
            $handled[] = $event->messageId;
        },
    );

    expect($retried->processed)->toBe(1)
        ->and($handled)->toBe(['msg-parity-1']);
})->with(['redis', 'rabbitmq']);

it('не роняет процесс при недоступном транспорте', function (string $transport): void {
    if ($transport === 'redis') {
        $bus = new RedisStreamEventBus(new FakeRedisConnection([], new RuntimeException('connection refused')), 'events.orders', '');
    } else {
        $broker = new PhpAmqpBroker([
            'host' => '127.0.0.1',
            'port' => 1,
            'user' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'heartbeat' => 0,
            'timeout' => 1,
            'prefetch' => 1,
        ]);
        $bus = new RabbitMqEventBus(
            $broker,
            new RabbitMqTopology([
                'exchange' => 'down.events',
                'dlx_exchange' => 'down.events.dlx',
                'message_ttl_seconds' => 60,
                'max_attempts' => 3,
            ], ['order' => 'orders'], ['events.orders']),
            0,
        );
    }

    $result = $bus->consume('events.orders', 'down-group', 'consumer-1', 10, static fn (): null => null);

    expect($result->degraded)->toBeTrue();

    $thrown = null;

    try {
        $bus->publish(monolithParityEnvelope());
    } catch (Throwable $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull();
})->with(['redis', 'rabbitmq']);
