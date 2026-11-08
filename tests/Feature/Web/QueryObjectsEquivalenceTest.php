<?php

declare(strict_types=1);

use App\Domain\Identity\TransportType;
use App\Domain\Order\OrderStatus;
use App\Models\Geography\ServiceZone;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use App\Services\Dispatch\DispatcherQueueQuery;
use App\Services\Order\OrderListQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Эквивалентность query-объектов (FR-UI-192)
|--------------------------------------------------------------------------
|
| Очередь диспетчера и список заказов с фильтром status=created читают одни
| и те же строки тем же запросом: очередь — надстройка над OrderListQuery.
|
*/

/**
 * Тексты SELECT-запросов, выполненных внутри участка кода.
 *
 * Служебные операторы транзакций не сохраняются: сравниваются только выборки.
 *
 * @param callable(): void $operation
 * @return list<string>
 */
function queryObjectsSelects(callable $operation): array
{
    /** @var list<string> $statements */
    $statements = [];

    DB::listen(static function (QueryExecuted $query) use (&$statements): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $statements[] = $query->toRawSql();
        }
    });

    $operation();

    return $statements;
}

it('строит очередь тем же запросом, что список с фильтром created', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();

    DeliveryOrder::factory()->count(3)->create();
    DeliveryOrder::factory()->withStatus(OrderStatus::Cancelled)->create();

    $orders = app(OrderListQuery::class);
    $queue = app(DispatcherQueueQuery::class);

    $listSql = queryObjectsSelects(static function () use ($orders, $dispatcher): void {
        $orders->paginate($orders->query($dispatcher, ['status' => [OrderStatus::Created->value]]), 20);
    });

    $queueSql = queryObjectsSelects(static function () use ($queue, $dispatcher): void {
        $queue->page($dispatcher, [], 20);
    });

    expect($queueSql)->toBe($listSql);
});

it('отдаёт те же заказы и в том же порядке, что список с фильтром created', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();

    DeliveryOrder::factory()->create([
        'status' => OrderStatus::Created,
        'created_at' => now()->subMinutes(10)->toDateTimeString(),
        'updated_at' => now()->subMinutes(10)->toDateTimeString(),
    ]);
    DeliveryOrder::factory()->create([
        'status' => OrderStatus::Created,
        'created_at' => now()->subMinutes(5)->toDateTimeString(),
        'updated_at' => now()->subMinutes(5)->toDateTimeString(),
    ]);
    DeliveryOrder::factory()->withStatus(OrderStatus::Assigned)->create();

    $orders = app(OrderListQuery::class);
    $queue = app(DispatcherQueueQuery::class);

    $listIds = $orders
        ->paginate($orders->query($dispatcher, ['status' => [OrderStatus::Created->value]]), 20)
        ->getCollection()
        ->pluck('id')
        ->all();

    $queueIds = $queue->page($dispatcher, [], 20)->getCollection()->pluck('id')->all();

    expect($queueIds)->toBe($listIds)
        ->and($queueIds)->toHaveCount(2);
});

it('не расширяет очередь чужим статусом из фильтров', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();

    DeliveryOrder::factory()->withStatus(OrderStatus::Cancelled)->create();

    expect(app(DispatcherQueueQuery::class)->page($dispatcher, ['status' => ['cancelled']])->items())->toBe([]);
});

it('фильтрует очередь по зоне и транспорту', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();
    $zone = ServiceZone::factory()->create();

    $inZone = DeliveryOrder::factory()->create(['zone_id' => $zone->id]);
    DeliveryOrder::factory()->create();

    $queue = app(DispatcherQueueQuery::class);

    expect($queue->page($dispatcher, ['zone_id' => $zone->id])->getCollection()->pluck('id')->all())
        ->toBe([$inZone->id]);

    $car = DeliveryOrder::factory()->create([
        'zone_id' => $zone->id,
        'transport_type' => TransportType::Car,
        'light_vehicle_kind' => null,
    ]);

    expect($queue->page($dispatcher, ['transport' => TransportType::Car->value])->getCollection()->pluck('id')->all())
        ->toBe([$car->id]);
});
