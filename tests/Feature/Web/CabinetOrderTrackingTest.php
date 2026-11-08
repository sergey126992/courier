<?php

declare(strict_types=1);

use App\Domain\Identity\Role;
use App\Domain\Order\OrderStatus;
use App\Models\Order\DeliveryOrder;
use App\Models\User;

/*
|--------------------------------------------------------------------------
| Доступ к трекингу заказа отправителя (ЧТЗ 9.1, раздел 8.1)
|--------------------------------------------------------------------------
|
| FR-UI-128: владелец видит блок отслеживания, гость уходит на вход,
| роль без доступа получает 403, чужой заказ неотличим от несуществующего.
|
*/

it('показывает трекинг владельцу', function (): void {
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->withStatus(OrderStatus::PickedUp)->create([
        'sender_id' => $sender->id,
    ]);

    $this->actingAs($sender)->get(route('cabinet.orders.tracking', $order))
        ->assertOk()
        ->assertSee(__('tracking.labels.public_title'))
        ->assertSee(__('tracking.labels.last_position'))
        ->assertSee(__('ui.actions.create_tracking_link'));
});

it('делает ссылку на трекинг активной в карточке заказа', function (): void {
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->create(['sender_id' => $sender->id]);

    $this->actingAs($sender)->get(route('cabinet.orders.show', $order))
        ->assertOk()
        ->assertSee(route('cabinet.orders.tracking', $order));
});

it('отправляет гостя на вход независимо от существования заказа', function (): void {
    $order = DeliveryOrder::factory()->create();

    $this->get(route('cabinet.orders.tracking', $order))->assertRedirect('/login');
    $this->get('/cabinet/orders/999999/tracking')->assertRedirect('/login');
});

it('запрещает трекинг ролям без доступа к заказам', function (Role $role): void {
    $order = DeliveryOrder::factory()->create();

    $user = $role === Role::Courier
        ? User::factory()->courier()->create()
        : User::factory()->admin()->create();

    $this->actingAs($user)->get(route('cabinet.orders.tracking', $order))->assertForbidden();
})->with([
    'курьер' => [Role::Courier],
    'администратор' => [Role::Admin],
]);

it('не отличает чужой заказ от несуществующего', function (): void {
    $owner = User::factory()->sender()->create();
    $foreign = DeliveryOrder::factory()->create(['sender_id' => $owner->id]);

    $this->actingAs(User::factory()->sender()->create());

    $foreignResponse = $this->get(route('cabinet.orders.tracking', $foreign));
    $missingResponse = $this->get('/cabinet/orders/999999/tracking');

    $foreignResponse->assertNotFound();
    $missingResponse->assertNotFound();

    // Тела ответов совпадают: раскрыть существование чужого заказа нельзя.
    expect($foreignResponse->getContent())->toBe($missingResponse->getContent());
});
