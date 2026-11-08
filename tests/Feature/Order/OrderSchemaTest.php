<?php

declare(strict_types=1);

use App\Domain\Order\Exceptions\StatusHistoryIsImmutable;
use App\Domain\Order\OrderNumber;
use App\Domain\Order\OrderStatus;
use App\Models\Geography\ServiceZone;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\IdempotencyKey;
use App\Models\Order\OrderStatusHistory;
use App\Models\Order\OutboxMessage;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Набор обязательных колонок заказа.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function orderRow(array $overrides = []): array
{
    return array_merge([
        'number' => 'CRG-2026-000001',
        'tracking_code' => 'trk_9f3a1c5e',
        'sender_id' => User::factory()->sender()->create()->id,
        'zone_id' => ServiceZone::factory()->create()->id,
        'status' => 'created',
        'transport_type' => 'light',
        'light_vehicle_kind' => 'bicycle',
        'pickup_address' => ['district' => 'Тверской', 'street' => 'Тверская', 'house' => '7', 'latitude' => 55.755826, 'longitude' => 37.6173],
        'dropoff_address' => ['district' => 'Тверской', 'street' => 'Тверская', 'house' => '9', 'latitude' => 55.768279, 'longitude' => 37.6173],
        'parcel' => ['weight_grams' => 1500, 'length_cm' => 30, 'width_cm' => 20, 'height_cm' => 10],
        'distance_km' => 1.8,
        'price_amount' => 19400,
        'price_currency' => 'RUB',
        'price_breakdown' => [['code' => 'base', 'label' => 'Базовая ставка', 'amount' => 19400]],
        'eta_minutes' => 15,
        'requested_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides);
}

it('создаёт таблицы заказов с ожидаемыми колонками', function (): void {
    expect(Schema::hasColumns('delivery_orders', [
        'id', 'number', 'tracking_code', 'sender_id', 'zone_id', 'status',
        'transport_type', 'light_vehicle_kind', 'pickup_address', 'dropoff_address',
        'parcel', 'distance_km', 'price_amount', 'price_currency', 'price_breakdown',
        'eta_minutes', 'requested_at', 'cancelled_at', 'created_at', 'updated_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('order_status_history', [
            'id', 'order_id', 'from_status', 'to_status', 'changed_by', 'reason', 'created_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('idempotency_keys', [
            'id', 'user_id', 'key', 'request_hash', 'status', 'response_code',
            'response_body', 'expires_at', 'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('outbox_messages', [
            'id', 'aggregate_type', 'aggregate_id', 'event_type', 'payload',
            'published_at', 'attempts', 'created_at',
        ]))->toBeTrue();
});

it('создаёт заказ фабрикой и приводит значения к доменным типам', function (): void {
    $order = DeliveryOrder::factory()->create();

    expect($order->status)->toBe(OrderStatus::Created)
        ->and($order->transport_type->value)->toBe('light')
        ->and($order->light_vehicle_kind?->value)->toBe('bicycle')
        ->and($order->pickup_address['latitude'])->toBe(55.755826)
        ->and($order->dropoff_address['house'])->toBe('9')
        ->and($order->parcel['weight_grams'])->toBe(1500)
        ->and($order->price_amount)->toBe(19400)
        ->and($order->price_breakdown)->toHaveCount(3)
        ->and($order->eta_minutes)->toBe(15)
        ->and($order->distance_km)->toBe(1.8)
        ->and($order->number)->toStartWith('CRG-')
        ->and($order->tracking_code)->toStartWith('trk_');
});

it('создаёт заказ в статусе «создан» и не даёт задать статус массово', function (): void {
    $attributes = orderRow(['status' => 'cancelled']);
    unset($attributes['created_at'], $attributes['updated_at']);

    // Статуса нет в fillable: запрос не может перевести заказ в другой статус.
    $order = DeliveryOrder::create($attributes);
    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Created);
});

it('не допускает второй заказ с тем же номером', function (): void {
    DeliveryOrder::factory()->create(['number' => 'CRG-2026-000001']);

    expect(static fn () => DeliveryOrder::factory()->create(['number' => 'CRG-2026-000001']))
        ->toThrow(QueryException::class);
});

it('не допускает второй заказ с тем же кодом трекинга', function (): void {
    DeliveryOrder::factory()->create(['tracking_code' => 'trk_9f3a1c5e']);

    expect(static fn () => DeliveryOrder::factory()->create(['tracking_code' => 'trk_9f3a1c5e']))
        ->toThrow(QueryException::class);
});

it('не допускает статус вне набора ЧТЗ', function (): void {
    expect(static fn () => DB::table('delivery_orders')->insert(orderRow(['status' => 'draft'])))
        ->toThrow(QueryException::class);
});

it('запрещает удаление зоны, на которую ссылаются заказы', function (): void {
    $order = DeliveryOrder::factory()->create();
    $zone = ServiceZone::query()->findOrFail($order->zone_id);

    // Ограничение базы (ON DELETE RESTRICT) не даёт удалить зону с заказами.
    expect(static fn () => $zone->delete())->toThrow(QueryException::class);
});

it('запрещает удаление отправителя, у которого есть заказы', function (): void {
    $order = DeliveryOrder::factory()->create();
    $sender = User::query()->findOrFail($order->sender_id);

    expect(static fn () => $sender->delete())->toThrow(QueryException::class);
});

it('выдаёт разные номера последовательностью базы', function (): void {
    $first = (array) DB::selectOne("select nextval('order_number_seq') as value");
    $second = (array) DB::selectOne("select nextval('order_number_seq') as value");

    $firstNumber = OrderNumber::fromSequence((int) $first['value'], 2026);
    $secondNumber = OrderNumber::fromSequence((int) $second['value'], 2026);

    expect($secondNumber->sequence())->toBe($firstNumber->sequence() + 1)
        ->and($firstNumber->value())->toMatch('/^CRG-2026-\d{6}$/');
});

it('не даёт изменить запись истории статусов', function (): void {
    $history = OrderStatusHistory::factory()->create();

    expect(static fn () => $history->update(['reason' => 'подмена']))
        ->toThrow(StatusHistoryIsImmutable::class);

    expect(static fn () => $history->save())
        ->toThrow(StatusHistoryIsImmutable::class);
});

it('не даёт удалить запись истории статусов', function (): void {
    $history = OrderStatusHistory::factory()->create();

    expect(static fn () => $history->delete())
        ->toThrow(StatusHistoryIsImmutable::class);

    $this->assertDatabaseHas('order_status_history', ['id' => $history->id]);
});

it('строит запись истории из перехода домена', function (): void {
    $order = DeliveryOrder::factory()->create();
    $author = User::query()->findOrFail($order->sender_id);

    $transition = orderAggregate()->cancel('Отправитель передумал');

    $history = OrderStatusHistory::create([
        'order_id' => $order->id,
        'from_status' => $transition->from(),
        'to_status' => $transition->to(),
        'changed_by' => $author->id,
        'reason' => $transition->reason(),
    ]);

    expect(OrderStatusHistory::query()->where('order_id', $order->id)->count())->toBe(1);

    $this->assertDatabaseHas('order_status_history', [
        'id' => $history->id,
        'order_id' => $order->id,
        'from_status' => 'created',
        'to_status' => 'cancelled',
        'changed_by' => $author->id,
        'reason' => 'Отправитель передумал',
    ]);
});

it('удаляет историю вместе с заказом', function (): void {
    $order = DeliveryOrder::factory()->create();
    OrderStatusHistory::factory()->create(['order_id' => $order->id]);

    $order->delete();

    $this->assertDatabaseMissing('order_status_history', ['order_id' => $order->id]);
});

it('держит ключ идемпотентности уникальным в пределах пользователя', function (): void {
    $user = User::factory()->sender()->create();

    $attributes = [
        'user_id' => $user->id,
        'key' => 'a0b1c2d3e4f5',
        'request_hash' => hash('sha256', 'body'),
        'status' => IdempotencyKey::STATUS_IN_PROGRESS,
        'expires_at' => now()->addDay(),
    ];

    IdempotencyKey::create($attributes);

    expect(static fn () => IdempotencyKey::create($attributes))
        ->toThrow(QueryException::class);
});

it('создаёт исходящее событие с нулём попыток', function (): void {
    $message = OutboxMessage::create([
        'aggregate_type' => 'delivery_order',
        'aggregate_id' => 1,
        'event_type' => 'order.created',
        'payload' => ['number' => 'CRG-2026-000001'],
    ]);

    $message->refresh();

    expect($message->attempts)->toBe(0)
        ->and($message->published_at)->toBeNull()
        ->and($message->payload)->toBe(['number' => 'CRG-2026-000001']);
});
