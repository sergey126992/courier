<?php

declare(strict_types=1);

use App\Domain\Events\AggregateType;
use App\Domain\Events\EventSchemaVersion;
use App\Domain\Events\EventType;
use App\Domain\Events\EventTypeRegistry;
use App\Domain\Events\Exceptions\EventTypeUnknown;

/**
 * Общий состав payload события заказа (FR-NTF-223, ЧТЗ 8.3).
 *
 * @return list<string>
 */
function asyncOrderEventFields(): array
{
    return ['event_id', 'order_id', 'tracking_code', 'sender_id', 'status', 'occurred_at', 'locale'];
}

/**
 * Реестр типов событий этапа M7.
 */
function asyncEventRegistry(): EventTypeRegistry
{
    return new EventTypeRegistry;
}

it('знает одиннадцать типов этапа', function (): void {
    $types = asyncEventRegistry()->types();

    expect($types)->toHaveCount(11)
        ->and($types)->toBe([
            'order.created',
            'order.assigned',
            'order.picked_up',
            'order.delivered',
            'order.failed',
            'order.cancelled',
            'courier.shift.started',
            'courier.shift.ended',
            'user.contact_changed',
            'notification.sent',
            'notification.failed',
        ]);
});

it('объявляет обязательные поля payload по таблице 8.4', function (string $type, array $fields): void {
    $registry = asyncEventRegistry();

    expect($registry->has($type))->toBeTrue()
        ->and($registry->requiredPayloadFields($type))->toBe($fields);
})->with([
    'создание' => [
        'order.created',
        [
            ...asyncOrderEventFields(),
            'pickup_district',
            'transport',
            'price_amount',
            'price_currency',
        ],
    ],
    'назначение' => [
        'order.assigned',
        [...asyncOrderEventFields(), 'assignment_id', 'courier_id', 'assigned_at'],
    ],
    'забор' => [
        'order.picked_up',
        [...asyncOrderEventFields(), 'assignment_id', 'courier_id', 'picked_up_at'],
    ],
    'вручение' => [
        'order.delivered',
        [...asyncOrderEventFields(), 'assignment_id', 'courier_id', 'delivered_at'],
    ],
    'неудача' => [
        'order.failed',
        [...asyncOrderEventFields(), 'assignment_id', 'courier_id', 'failure_reason', 'failed_at'],
    ],
    'отмена' => [
        'order.cancelled',
        [...asyncOrderEventFields(), 'cancelled_by', 'cancelled_by_role', 'cancelled_at', 'reason_code'],
    ],
    'открытие смены' => [
        'courier.shift.started',
        ['shift_id', 'courier_id', 'zone_id', 'started_at'],
    ],
    'закрытие смены' => [
        'courier.shift.ended',
        ['shift_id', 'courier_id', 'ended_at'],
    ],
    'смена контактов' => [
        'user.contact_changed',
        ['event_id', 'user_id', 'email', 'locale', 'occurred_at'],
    ],
    'уведомление отправлено' => [
        'notification.sent',
        [
            'event_id',
            'notification_id',
            'order_id',
            'recipient_id',
            'channel',
            'status',
            'attempt',
            'occurred_at',
            'correlation_id',
        ],
    ],
    'уведомление не доставлено' => [
        'notification.failed',
        [
            'event_id',
            'notification_id',
            'order_id',
            'recipient_id',
            'channel',
            'status',
            'attempt',
            'occurred_at',
            'correlation_id',
            'error_code',
        ],
    ],
]);

it('указывает владельца агрегата для каждого типа', function (): void {
    $registry = asyncEventRegistry();

    expect($registry->aggregateType('order.created'))->toBe(AggregateType::Order)
        ->and($registry->aggregateType('order.cancelled'))->toBe(AggregateType::Order)
        ->and($registry->aggregateType('courier.shift.started'))->toBe(AggregateType::CourierShift)
        ->and($registry->aggregateType('courier.shift.ended'))->toBe(AggregateType::CourierShift)
        ->and($registry->aggregateType('user.contact_changed'))->toBe(AggregateType::User)
        ->and($registry->aggregateType('notification.sent'))->toBe(AggregateType::Notification)
        ->and($registry->aggregateType('notification.failed'))->toBe(AggregateType::Notification);
});

it('фиксирует версию контракта v1', function (): void {
    expect(asyncEventRegistry()->schemaVersion())->toBe(EventSchemaVersion::V1)
        ->and(asyncEventRegistry()->schemaVersion()->value)->toBe('v1');
});

it('отвергает незарегистрированный тип кодом EVENT_TYPE_UNKNOWN', function (): void {
    $registry = asyncEventRegistry();

    $exception = null;

    try {
        $registry->get('order.closed');
    } catch (EventTypeUnknown $thrown) {
        $exception = $thrown;
    }

    expect($registry->has('order.closed'))->toBeFalse()
        ->and($exception)->toBeInstanceOf(EventTypeUnknown::class)
        ->and($exception?->errorCode())->toBe('EVENT_TYPE_UNKNOWN')
        ->and($exception?->eventType())->toBe('order.closed');
});

it('держит перечень в согласии с перечислением типов', function (): void {
    $values = array_map(static fn (EventType $type): string => $type->value, EventType::cases());

    expect(asyncEventRegistry()->types())->toBe($values);
});
