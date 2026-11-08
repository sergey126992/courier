<?php

declare(strict_types=1);

use App\Domain\Events\EventEnvelope;
use App\Domain\Events\EventPayload;
use App\Domain\Events\EventSchemaVersion;
use App\Domain\Events\EventType;
use App\Services\Events\Exceptions\EventDeliveryFailed;
use App\Services\Events\RabbitMqEventBus;
use App\Services\Events\RabbitMqTopology;
use Tests\Support\FakeAmqpBroker;

/*
|--------------------------------------------------------------------------
| Шина событий на RabbitMQ (FR-NTF-541…552)
|--------------------------------------------------------------------------
|
| Проверяется тот же контракт EventBus: публикация в topic exchange,
| подтверждение только после обработчика, повтор неподтверждённого
| сообщения и уход в DLX после предела попыток. Брокер подменён,
| сеть не используется (ЧТЗ 9.7).
|
*/

/**
 * Топология теста: уникальные имена обменника и очередей.
 *
 * @return array{array<string, mixed>, RabbitMqTopology}
 */
function rabbitBusTopology(int $maxAttempts = 3): array
{
    $config = [
        'exchange' => 'test.events',
        'dlx_exchange' => 'test.events.dlx',
        'message_ttl_seconds' => 60,
        'max_attempts' => $maxAttempts,
    ];

    $topology = new RabbitMqTopology(
        $config,
        ['order' => 'orders', 'courier' => 'orders', 'user' => 'contacts', 'notification' => 'notifications'],
        ['events.orders', 'events.contacts', 'events.notifications'],
    );

    return [$config, $topology];
}

/**
 * Конверт события заказа.
 */
function rabbitBusEnvelope(string $messageId = 'msg-rabbit-1'): EventEnvelope
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
        correlationId: 'corr-rabbit-1',
    );
}

it('публикует событие в topic exchange с ключом по домену', function (): void {
    [, $topology] = rabbitBusTopology();
    $broker = new FakeAmqpBroker;

    $bus = new RabbitMqEventBus($broker, $topology, 0);
    $bus->publish(rabbitBusEnvelope());

    expect($broker->published)->toHaveCount(1)
        ->and($broker->published[0]['exchange'])->toBe('test.events')
        ->and($broker->published[0]['routing_key'])->toBe('orders.order.created');
});

it('подтверждает сообщение только после обработчика', function (): void {
    [, $topology] = rabbitBusTopology();
    $broker = new FakeAmqpBroker;
    $bus = new RabbitMqEventBus($broker, $topology, 0);

    $bus->consume('events.orders', 'group-1', 'consumer-1', 10, static fn (): null => null);
    $bus->publish(rabbitBusEnvelope());

    $handled = [];

    $result = $bus->consume(
        'events.orders',
        'group-1',
        'consumer-1',
        10,
        static function (EventEnvelope $event) use (&$handled): void {
            $handled[] = $event->messageId;
        },
    );

    expect($result->read)->toBe(1)
        ->and($result->processed)->toBe(1)
        ->and($result->acknowledged)->toBe(1)
        ->and($handled)->toBe(['msg-rabbit-1'])
        ->and($broker->acknowledged)->toHaveCount(1);
});

it('повторяет неподтверждённое сообщение и уводит его в DLX после предела попыток', function (): void {
    [, $topology] = rabbitBusTopology(maxAttempts: 1);
    $broker = new FakeAmqpBroker;
    $bus = new RabbitMqEventBus($broker, $topology, 0);

    $bus->consume('events.orders', 'group-1', 'consumer-1', 10, static fn (): null => null);
    $bus->publish(rabbitBusEnvelope());

    $failing = static function (): void {
        throw new RuntimeException('effect failed');
    };

    $first = $bus->consume('events.orders', 'group-1', 'consumer-1', 10, $failing);

    expect($first->failed)->toBe(1)
        ->and($broker->acknowledged)->toBe([]);

    // Следующий проход фиксирует отсутствие ack и отправляет сообщение в DLX.
    $bus->consume('events.orders', 'group-1', 'consumer-1', 10, $failing);

    expect($broker->rejected)->toHaveCount(1);
});

it('не считает событие опубликованным, когда очередь удалена', function (): void {
    [, $topology] = rabbitBusTopology();
    $broker = new FakeAmqpBroker;
    $broker->failUnroutable = true;
    $bus = new RabbitMqEventBus($broker, $topology, 0);

    $bus->consume('events.orders', 'group-1', 'consumer-1', 10, static fn (): null => null);
    // Удалённая очередь: привязок больше нет, брокер вернёт сообщение.
    $broker->bindings = [];

    expect(fn (): null => $bus->publish(rabbitBusEnvelope()))->toThrow(EventDeliveryFailed::class);
});

it('не роняет процесс при недоступном брокере', function (): void {
    [, $topology] = rabbitBusTopology();
    $broker = new FakeAmqpBroker;
    $broker->available = false;
    $bus = new RabbitMqEventBus($broker, $topology, 0);

    $result = $bus->consume('events.orders', 'group-1', 'consumer-1', 10, static fn (): null => null);

    expect($result->degraded)->toBeTrue();

    expect(fn (): null => $bus->publish(rabbitBusEnvelope()))->toThrow(EventDeliveryFailed::class);
});
