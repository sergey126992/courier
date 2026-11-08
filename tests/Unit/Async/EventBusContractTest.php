<?php

declare(strict_types=1);

use App\Domain\Events\EventEnvelope;
use App\Domain\Events\EventPayload;
use App\Domain\Events\EventType;
use App\Services\Events\ConsumeResult;
use App\Services\Events\Contracts\EventBus;
use App\Services\Events\Exceptions\EventDeliveryFailed;
use App\Services\Events\LocalEventBus;
use Tests\Support\RecordingEventListener;

/**
 * Конверт события заказа с обязательными полями payload реестра M7.
 */
function eventBusEnvelope(string $messageId = 'msg-1'): EventEnvelope
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

it('объявляет публикацию, потребление, подтверждение и возврат в pending', function (): void {
    $contract = new ReflectionClass(EventBus::class);

    $names = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        $contract->getMethods(),
    );

    expect($names)->toContain('publish')
        ->and($names)->toContain('consume')
        ->and($names)->toContain('ack')
        ->and($names)->toContain('reclaim');

    $consume = $contract->getMethod('consume');
    $returnType = $consume->getReturnType();

    expect($returnType instanceof ReflectionNamedType ? $returnType->getName() : null)->toBe(ConsumeResult::class)
        ->and($consume->getNumberOfParameters())->toBe(5)
        ->and($contract->getMethod('publish')->getNumberOfParameters())->toBe(1)
        ->and($contract->getMethod('ack')->getNumberOfParameters())->toBe(3)
        ->and($contract->getMethod('reclaim')->getNumberOfParameters())->toBe(6);
});

it('описывает результат потребления счётчиками и идентификаторами', function (): void {
    $empty = ConsumeResult::empty();

    expect($empty->read)->toBe(0)
        ->and($empty->processed)->toBe(0)
        ->and($empty->acknowledged)->toBe(0)
        ->and($empty->failed)->toBe(0)
        ->and($empty->invalid)->toBe(0)
        ->and($empty->messageIds)->toBe([])
        ->and($empty->degraded)->toBeFalse()
        ->and($empty->hasFailures())->toBeFalse();

    $partial = new ConsumeResult(
        read: 2,
        processed: 1,
        acknowledged: 1,
        failed: 1,
        messageIds: ['1-0', '2-0'],
    );

    expect($partial->hasFailures())->toBeTrue()
        ->and($partial->messageIds)->toBe(['1-0', '2-0'])
        ->and($partial->acknowledged)->toBe(1);

    expect(ConsumeResult::degraded()->degraded)->toBeTrue();
});

it('остаётся совместимой локальной реализацией в режиме in-memory', function (): void {
    $bus = new LocalEventBus;
    $listener = new RecordingEventListener;

    $bus->register(EventType::OrderCreated, $listener);

    expect($bus)->toBeInstanceOf(EventBus::class);

    $bus->publish(eventBusEnvelope('msg-1'));
    $bus->dispatch(eventBusEnvelope('msg-2'));

    expect($listener->handled)->toBe(['msg-1', 'msg-2']);
});

it('считает отказ слушателя ошибкой доставки', function (): void {
    $bus = new LocalEventBus;
    $bus->register(EventType::OrderCreated, new RecordingEventListener(failing: true));

    expect(fn () => $bus->publish(eventBusEnvelope()))
        ->toThrow(EventDeliveryFailed::class);
});

it('выдаёт сообщения потока, подтверждает успешные и оставляет отказ в pending', function (): void {
    $bus = new LocalEventBus;
    $bus->enqueue('events.orders', eventBusEnvelope('msg-1'), '1-0');
    $bus->enqueue('events.orders', eventBusEnvelope('msg-2'), '2-0');

    $handled = [];

    $result = $bus->consume(
        'events.orders',
        'notifications-service',
        'consumer-1',
        10,
        function (EventEnvelope $event, string $messageId) use (&$handled): void {
            $handled[] = $messageId;

            if ($messageId === '2-0') {
                throw new RuntimeException('Сбой обработчика.');
            }
        },
    );

    expect($result->read)->toBe(2)
        ->and($result->processed)->toBe(1)
        ->and($result->acknowledged)->toBe(1)
        ->and($result->failed)->toBe(1)
        ->and($result->messageIds)->toBe(['1-0', '2-0'])
        ->and($handled)->toBe(['1-0', '2-0']);

    $empty = $bus->consume('events.orders', 'notifications-service', 'consumer-1', 10, fn () => null);

    expect($empty->read)->toBe(0);

    $reclaimed = $bus->reclaim(
        'events.orders',
        'notifications-service',
        'consumer-1',
        0,
        10,
        function (EventEnvelope $event, string $messageId) use (&$handled): void {
            $handled[] = $messageId;
        },
    );

    expect($reclaimed->read)->toBe(1)
        ->and($reclaimed->acknowledged)->toBe(1)
        ->and($handled)->toBe(['1-0', '2-0', '2-0']);

    $nothing = $bus->reclaim('events.orders', 'notifications-service', 'consumer-1', 0, 10, fn () => null);

    expect($nothing->read)->toBe(0);
});

it('снимает сообщение с выдачи явным подтверждением', function (): void {
    $bus = new LocalEventBus;
    $bus->enqueue('events.orders', eventBusEnvelope('msg-1'), '1-0');

    $bus->consume('events.orders', 'group', 'consumer', 10, function (): void {
        throw new RuntimeException('Сбой обработчика.');
    });

    $bus->ack('events.orders', 'group', '1-0');

    expect($bus->reclaim('events.orders', 'group', 'consumer', 0, 10, fn () => null)->read)->toBe(0);
});

it('ограничивает пачку потребления', function (): void {
    $bus = new LocalEventBus;

    expect(fn () => $bus->consume('events.orders', 'group', 'consumer', 0, fn () => null))
        ->toThrow(InvalidArgumentException::class);
});
