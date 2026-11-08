<?php

declare(strict_types=1);

use App\Domain\Events\AggregateType;
use App\Domain\Events\EventEnvelope;
use App\Domain\Events\EventPayload;
use App\Domain\Events\EventSchemaVersion;
use App\Domain\Events\EventType;
use App\Domain\Events\EventTypeRegistry;
use App\Domain\Events\Exceptions\EventPayloadInvalid;
use App\Domain\Events\Exceptions\EventTypeUnknown;

/**
 * Payload из обязательных полей типа: значения для контракта не важны.
 *
 * @return array<string, mixed>
 */
function asyncEnvelopePayload(EventType $type): array
{
    return array_fill_keys($type->requiredPayloadFields(), 'значение');
}

/**
 * Конверт заказа из обязательных полей типа.
 */
function asyncEnvelope(EventType $type = EventType::OrderCreated): EventEnvelope
{
    return new EventEnvelope(
        messageId: '6f1c3f7e-0c1a-4d4e-9b6f-2a1f2c3d4e5f',
        eventType: $type,
        aggregateId: 42,
        occurredAt: new DateTimeImmutable('2026-05-01T12:00:00+00:00'),
        payload: EventPayload::fromArray(asyncEnvelopePayload($type)),
        correlationId: 'req-6f1c3f7e',
    );
}

it('содержит восемь полей конверта вместе с correlation_id', function (): void {
    $array = asyncEnvelope()->toArray();

    expect(array_keys($array))->toBe([
        'message_id',
        'event_type',
        'schema_version',
        'correlation_id',
        'occurred_at',
        'aggregate_type',
        'aggregate_id',
        'payload',
    ])
        ->and($array['message_id'])->toBe('6f1c3f7e-0c1a-4d4e-9b6f-2a1f2c3d4e5f')
        ->and($array['event_type'])->toBe('order.created')
        ->and($array['schema_version'])->toBe('v1')
        ->and($array['correlation_id'])->toBe('req-6f1c3f7e')
        ->and($array['occurred_at'])->toBe('2026-05-01T12:00:00+00:00')
        ->and($array['aggregate_type'])->toBe('order')
        ->and($array['aggregate_id'])->toBe(42)
        ->and($array['payload'])->toBe(asyncEnvelopePayload(EventType::OrderCreated));
});

it('допускает историческое событие без correlation_id', function (): void {
    $envelope = new EventEnvelope(
        messageId: '6f1c3f7e-0c1a-4d4e-9b6f-2a1f2c3d4e5f',
        eventType: EventType::OrderCreated,
        aggregateId: 42,
        occurredAt: new DateTimeImmutable('2026-05-01T12:00:00+00:00'),
        payload: EventPayload::fromArray(asyncEnvelopePayload(EventType::OrderCreated)),
    );

    expect($envelope->correlationId)->toBeNull()
        ->and($envelope->toArray()['correlation_id'])->toBeNull();
});

it('выводит владельца агрегата из типа события', function (): void {
    expect(asyncEnvelope(EventType::OrderDelivered)->aggregateType)->toBe(AggregateType::Order)
        ->and(asyncEnvelope(EventType::CourierShiftStarted)->aggregateType)->toBe(AggregateType::CourierShift)
        ->and(asyncEnvelope(EventType::CourierShiftEnded)->aggregateType)->toBe(AggregateType::CourierShift);
});

it('строит конверт для каждого зарегистрированного типа', function (string $type): void {
    $envelope = asyncEnvelope(EventType::from($type));

    expect($envelope->eventType->value)->toBe($type)
        ->and($envelope->schemaVersion)->toBe(EventSchemaVersion::V1);
})->with([
    'order.created',
    'order.assigned',
    'order.picked_up',
    'order.delivered',
    'order.failed',
    'order.cancelled',
    'courier.shift.started',
    'courier.shift.ended',
]);

it('отвергает payload без обязательного поля', function (): void {
    $payload = asyncEnvelopePayload(EventType::OrderCreated);
    unset($payload['sender_id']);

    $exception = null;

    try {
        new EventEnvelope(
            messageId: '6f1c3f7e-0c1a-4d4e-9b6f-2a1f2c3d4e5f',
            eventType: EventType::OrderCreated,
            aggregateId: 42,
            occurredAt: new DateTimeImmutable('2026-05-01T12:00:00+00:00'),
            payload: EventPayload::fromArray($payload),
        );
    } catch (EventPayloadInvalid $thrown) {
        $exception = $thrown;
    }

    expect($exception)->toBeInstanceOf(EventPayloadInvalid::class)
        ->and($exception?->errorCode())->toBe('EVENT_PAYLOAD_INVALID')
        ->and($exception?->missingFields())->toBe(['sender_id'])
        ->and($exception?->eventType())->toBe('order.created');
});

it('допускает null в обязательном поле отмены', function (): void {
    $payload = asyncEnvelopePayload(EventType::OrderCancelled);
    $payload['reason_code'] = null;

    $envelope = new EventEnvelope(
        messageId: '6f1c3f7e-0c1a-4d4e-9b6f-2a1f2c3d4e5f',
        eventType: EventType::OrderCancelled,
        aggregateId: 42,
        occurredAt: new DateTimeImmutable('2026-05-01T12:00:00+00:00'),
        payload: EventPayload::fromArray($payload),
    );

    expect($envelope->payload->toArray()['reason_code'])->toBeNull()
        ->and($envelope->payload->has('reason_code'))->toBeTrue();
});

it('не принимает тип вне реестра', function (): void {
    $registry = new EventTypeRegistry;

    expect(fn () => $registry->get('order.closed'))->toThrow(EventTypeUnknown::class);
});
