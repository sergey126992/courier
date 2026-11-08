<?php

declare(strict_types=1);

use App\Domain\Order\OrderStatus;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OrderStatusHistory;
use App\Models\User;
use App\Services\Dispatch\DispatcherQueueQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
|--------------------------------------------------------------------------
| Бюджет SQL-запросов интерфейса (раздел 7 ЧТЗ)
|--------------------------------------------------------------------------
|
| Список — не более 3 запросов, карточка — не более 5, очередь — не более 3,
| кандидаты — не более 5. Считаются только SELECT: служебные операторы
| транзакций не входят в бюджет.
|
*/

/**
 * Число SELECT-запросов, выполненных внутри участка кода.
 *
 * @param callable(): void $operation
 */
function queryBudgetSelects(callable $operation): int
{
    $collector = new class
    {
        public int $count = 0;
    };

    DB::listen(static function (QueryExecuted $query) use ($collector): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $collector->count++;
        }
    });

    $operation();

    return $collector->count;
}

it('укладывает список заказов в три запроса', function (): void {
    $sender = User::factory()->sender()->create();
    DeliveryOrder::factory()->count(3)->create(['sender_id' => $sender->id]);

    Sanctum::actingAs($sender);

    $selects = queryBudgetSelects(function (): void {
        $this->getJson('/api/v1/orders')->assertOk();
    });

    expect($selects)->toBeLessThanOrEqual(3);
});

it('укладывает карточку заказа в пять запросов', function (): void {
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->withStatus(OrderStatus::Cancelled)->create(['sender_id' => $sender->id]);
    OrderStatusHistory::factory()->create(['order_id' => $order->id, 'changed_by' => $sender->id]);

    Sanctum::actingAs($sender);

    $selects = queryBudgetSelects(function () use ($order): void {
        $this->getJson('/api/v1/orders/'.$order->id)->assertOk();
    });

    expect($selects)->toBeLessThanOrEqual(5);
});

it('укладывает очередь диспетчера в три запроса', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();
    DeliveryOrder::factory()->count(3)->create();

    $selects = queryBudgetSelects(static function () use ($dispatcher): void {
        app(DispatcherQueueQuery::class)->page($dispatcher);
    });

    expect($selects)->toBeLessThanOrEqual(3);
});

it('укладывает панель кандидатов в пять запросов', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();
    $order = DeliveryOrder::factory()->create();

    Sanctum::actingAs($dispatcher);

    $selects = queryBudgetSelects(function () use ($order): void {
        $this->getJson('/api/v1/orders/'.$order->id.'/candidates')->assertOk();
    });

    expect($selects)->toBeLessThanOrEqual(5);
});
