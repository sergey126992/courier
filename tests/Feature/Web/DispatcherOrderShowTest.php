<?php

declare(strict_types=1);

use App\Domain\Identity\Role;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Доступ к карточке заказа диспетчера (ЧТЗ 9.1, раздел 8.1)
|--------------------------------------------------------------------------
|
| FR-UI-119, 134: гость уходит на вход, роль без права подбора получает 403,
| несуществующий заказ — 404, диспетчер видит карточку с кандидатами и
| назначением. Рендер карточки — не более 5 запросов.
|
*/

it('показывает карточку диспетчеру с действиями назначения', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();
    $order = DeliveryOrder::factory()->create();

    $this->actingAs($dispatcher)->get(route('dispatcher.orders.show', $order))
        ->assertOk()
        ->assertSee($order->number)
        ->assertSee(__('ui.actions.show_candidates'))
        ->assertSee(__('ui.actions.assign'))
        ->assertSee(__('ui.actions.reassign'))
        ->assertSee(__('orders.labels.history'))
        ->assertSee(__('dispatch.labels.back_to_queue'));
});

it('закрывает карточку гостю редиректом на вход независимо от существования заказа', function (): void {
    $order = DeliveryOrder::factory()->create();

    // Authenticate выполняется раньше SubstituteBindings: гость не различает заказы.
    $this->get(route('dispatcher.orders.show', $order))->assertRedirect('/login');
    $this->get('/dispatcher/orders/999999')->assertRedirect('/login');
});

it('запрещает карточку ролям без права подбора кандидатов', function (Role $role): void {
    $order = DeliveryOrder::factory()->create();

    $user = match ($role) {
        Role::Sender => User::factory()->sender()->create(),
        Role::Courier => User::factory()->courier()->create(),
        default => User::factory()->admin()->create(),
    };

    $this->actingAs($user)->get(route('dispatcher.orders.show', $order))->assertForbidden();
})->with([
    'отправитель' => [Role::Sender],
    'курьер' => [Role::Courier],
    'администратор' => [Role::Admin],
]);

it('не отличает несуществующий заказ от отсутствующего в базе', function (): void {
    $this->actingAs(User::factory()->dispatcher()->create());

    $first = $this->get('/dispatcher/orders/999998');
    $second = $this->get('/dispatcher/orders/999999');

    $first->assertNotFound();
    $second->assertNotFound();

    // Тела ответов совпадают: раскрыть существование заказа нельзя (FR-UI-119).
    expect($first->getContent())->toBe($second->getContent());
});

it('укладывает рендер карточки в пять запросов', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();
    $order = DeliveryOrder::factory()->create();

    $selects = 0;

    DB::listen(static function (QueryExecuted $query) use (&$selects): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $selects++;
        }
    });

    $this->actingAs($dispatcher)->get(route('dispatcher.orders.show', $order))->assertOk();

    expect($selects)->toBeLessThanOrEqual(5);
});

it('делает ссылку из очереди на карточку активной', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();
    $order = DeliveryOrder::factory()->create();

    $this->actingAs($dispatcher)->get(route('dispatcher.queue'))
        ->assertOk()
        ->assertSee(route('dispatcher.orders.show', $order), false);
});
