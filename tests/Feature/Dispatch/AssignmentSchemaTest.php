<?php

declare(strict_types=1);

use App\Domain\Dispatch\AssignmentStatus;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Строка назначения в обход модели: статус меняется только операциями домена.
 *
 * @return array<string, mixed>
 */
function dispatchAssignmentRow(int $orderId, int $courierId, string $status): array
{
    return [
        'order_id' => $orderId,
        'courier_id' => $courierId,
        'status' => $status,
        'strategy' => 'nearest',
        'distance_to_pickup_km' => 1.5,
        'assigned_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

it('создаёт таблицу назначений с ожидаемыми колонками', function (): void {
    expect(Schema::hasColumns('assignments', [
        'id', 'order_id', 'courier_id', 'status', 'strategy', 'distance_to_pickup_km',
        'assigned_at', 'accepted_at', 'picked_up_at', 'delivered_at', 'failed_at',
        'cancelled_at', 'failure_reason', 'failure_comment', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('не даёт второе активное назначение одного курьера', function (): void {
    $courier = User::factory()->courier()->create();

    Assignment::factory()->for($courier, 'courier')->active()->create();

    expect(static fn () => Assignment::factory()->for($courier, 'courier')->active()->create())
        ->toThrow(QueryException::class);
});

it('не даёт второе активное назначение на один заказ', function (): void {
    $order = DeliveryOrder::factory()->create();

    Assignment::factory()->for($order, 'order')->active()->create();

    expect(static fn () => Assignment::factory()->for($order, 'order')->active()->create())
        ->toThrow(QueryException::class);
});

it('разрешает новую активную попытку после терминального статуса', function (): void {
    $order = DeliveryOrder::factory()->create();
    $courier = User::factory()->courier()->create();
    $otherCourier = User::factory()->courier()->create();

    $failed = Assignment::factory()
        ->for($order, 'order')
        ->for($courier, 'courier')
        ->active()
        ->create();

    // Переход выполняет доменная операция, поэтому статус меняется в обход модели.
    DB::table('assignments')->where('id', $failed->id)->update([
        'status' => AssignmentStatus::Failed->value,
        'failed_at' => now(),
        'updated_at' => now(),
    ]);

    Assignment::factory()
        ->for($order, 'order')
        ->for($otherCourier, 'courier')
        ->active()
        ->create();

    expect(DB::table('assignments')->where('order_id', $order->id)->count())->toBe(2)
        ->and(Assignment::query()->where('order_id', $order->id)->active()->count())->toBe(1);
});

it('позволяет несколько терминальных попыток на один заказ', function (): void {
    $order = DeliveryOrder::factory()->create();

    Assignment::factory()->for($order, 'order')->failed()->create();
    Assignment::factory()->for($order, 'order')->cancelled()->create();

    expect(Assignment::query()->where('order_id', $order->id)->count())->toBe(2);
});

it('не допускает статус вне набора ЧТЗ', function (): void {
    $order = DeliveryOrder::factory()->create();
    $courier = User::factory()->courier()->create();

    expect(static fn () => DB::table('assignments')
        ->insert(dispatchAssignmentRow($order->id, $courier->id, 'lost')))
        ->toThrow(QueryException::class);
});

it('держит уникальность только по активным статусам', function (): void {
    // Ограничение частичное: в определении индекса есть условие по статусам.
    $indexes = DB::table('pg_indexes')
        ->where('tablename', 'assignments')
        ->pluck('indexdef', 'indexname');

    expect($indexes['assignments_active_order_unique'])->toContain('UNIQUE')
        ->and($indexes['assignments_active_order_unique'])->toContain('WHERE')
        ->and($indexes['assignments_active_order_unique'])->toContain("'picked_up'")
        ->and($indexes['assignments_active_courier_unique'])->toContain('UNIQUE')
        ->and($indexes['assignments_active_courier_unique'])->toContain('WHERE');
});

it('приводит статус и правило к доменным типам', function (): void {
    $assignment = Assignment::factory()->accepted()->create();

    expect($assignment->status)->toBe(AssignmentStatus::Accepted)
        ->and($assignment->isActive())->toBeTrue()
        ->and($assignment->strategy->value)->toBe('nearest');
});

it('запрещает удаление заказа, у которого есть назначение', function (): void {
    $assignment = Assignment::factory()->create();
    $order = DeliveryOrder::query()->findOrFail($assignment->order_id);

    expect(static fn () => $order->delete())->toThrow(QueryException::class);
});
