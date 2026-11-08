<?php

declare(strict_types=1);

use App\Domain\Identity\Role;
use App\Domain\Order\OrderStatus;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OrderStatusHistory;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Доступ к карточке заказа отправителя (ЧТЗ 9.1, раздел 8.1)
|--------------------------------------------------------------------------
|
| FR-UI-119, 126: гость уходит на вход, роль без доступа получает 403,
| чужой и несуществующий заказ неотличимы (одинаковый 404), владелец видит
| карточку с историей и автором. Рендер карточки — не более 5 запросов.
|
*/

it('показывает карточку владельцу с историей и автором', function (): void {
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->create(['sender_id' => $sender->id]);

    OrderStatusHistory::factory()
        ->transition(OrderStatus::Created, OrderStatus::Assigned, $sender, 'Назначен вручную')
        ->create(['order_id' => $order->id]);

    $this->actingAs($sender)->get(route('cabinet.orders.show', $order))
        ->assertOk()
        ->assertSee($order->number)
        ->assertSee($order->tracking_code)
        ->assertSee('Тверская')
        ->assertSee(__('orders.labels.history'))
        ->assertSee($sender->name)
        ->assertSee('Назначен вручную')
        ->assertSee(__('ui.actions.back_to_orders'));
});

it('закрывает карточку гостю редиректом на вход независимо от существования заказа', function (): void {
    $order = DeliveryOrder::factory()->create();

    // Гость не должен различать существующий и несуществующий заказ:
    // Authenticate выполняется раньше SubstituteBindings (FR-UI-119).
    $this->get(route('cabinet.orders.show', $order))->assertRedirect('/login');
    $this->get('/cabinet/orders/999999')->assertRedirect('/login');
});

it('запрещает карточку ролям без доступа к заказам', function (Role $role): void {
    $order = DeliveryOrder::factory()->create();

    $user = $role === Role::Courier
        ? User::factory()->courier()->create()
        : User::factory()->admin()->create();

    $this->actingAs($user)->get(route('cabinet.orders.show', $order))->assertForbidden();
})->with([
    'курьер' => [Role::Courier],
    'администратор' => [Role::Admin],
]);

it('не отличает чужой заказ от несуществующего', function (): void {
    $owner = User::factory()->sender()->create();
    $foreign = DeliveryOrder::factory()->create(['sender_id' => $owner->id]);

    $this->actingAs(User::factory()->sender()->create());

    $foreignResponse = $this->get(route('cabinet.orders.show', $foreign));
    $missingResponse = $this->get('/cabinet/orders/999999');

    $foreignResponse->assertNotFound();
    $missingResponse->assertNotFound();

    // Тела ответов совпадают: раскрыть существование чужого заказа нельзя.
    expect($foreignResponse->getContent())->toBe($missingResponse->getContent());
});

it('укладывает рендер карточки в пять запросов', function (): void {
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->create(['sender_id' => $sender->id]);

    OrderStatusHistory::factory()->count(3)->create([
        'order_id' => $order->id,
        'changed_by' => $sender->id,
    ]);

    $selects = 0;

    DB::listen(static function (QueryExecuted $query) use (&$selects): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $selects++;
        }
    });

    $this->actingAs($sender)->get(route('cabinet.orders.show', $order))->assertOk();

    expect($selects)->toBeLessThanOrEqual(5);
});
