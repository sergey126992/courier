<?php

declare(strict_types=1);

use App\Domain\Identity\Role;
use App\Domain\Order\OrderStatus;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OrderStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\Sanctum;

it('отдаёт карточку заказа с историей, автором и причиной', function (): void {
    $sender = User::factory()->sender()->create();
    $dispatcher = User::factory()->dispatcher()->create();

    $order = DeliveryOrder::factory()->withStatus(OrderStatus::Cancelled)->create(['sender_id' => $sender->id]);
    OrderStatusHistory::factory()->create(['order_id' => $order->id, 'changed_by' => $sender->id]);
    OrderStatusHistory::factory()->transition(OrderStatus::Created, OrderStatus::Cancelled, $dispatcher, 'Отменён диспетчером')
        ->create(['order_id' => $order->id]);

    Sanctum::actingAs($sender);

    $response = $this->getJson('/api/v1/orders/'.$order->id)->assertOk();

    $response->assertJsonPath('data.number', $order->number)
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.parcel.weight_grams', 1500)
        ->assertJsonPath('data.pickup.street', 'Тверская')
        ->assertJsonPath('data.price.amount', $order->price_amount)
        ->assertJsonCount(2, 'data.history')
        ->assertJsonPath('data.history.0.from_status', null)
        ->assertJsonPath('data.history.0.to_status', 'created')
        ->assertJsonPath('data.history.1.from_status', 'created')
        ->assertJsonPath('data.history.1.to_status', 'cancelled')
        ->assertJsonPath('data.history.1.reason', 'Отменён диспетчером')
        ->assertJsonPath('data.history.1.changed_by.id', $dispatcher->id);
});

it('не отличает чужой заказ от несуществующего', function (): void {
    $owner = User::factory()->sender()->create();
    $foreign = DeliveryOrder::factory()->create(['sender_id' => $owner->id]);

    Sanctum::actingAs(User::factory()->sender()->create());

    $foreignResponse = $this->getJson('/api/v1/orders/'.$foreign->id);
    $missingResponse = $this->getJson('/api/v1/orders/999999');

    $foreignResponse->assertStatus(404)->assertHeader('Content-Type', 'application/problem+json');
    $missingResponse->assertStatus(404);

    $foreignBody = (array) $foreignResponse->json();
    $missingBody = (array) $missingResponse->json();

    // Тела совпадают: отличается только адрес запроса в поле instance.
    unset($foreignBody['instance'], $missingBody['instance']);

    expect($foreignBody)->toBe($missingBody);
});

it('закрывает карточку ролям без доступа', function (Role $role): void {
    $order = DeliveryOrder::factory()->create();

    $user = $role === Role::Courier
        ? User::factory()->courier()->create()
        : User::factory()->admin()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/orders/'.$order->id)->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
})->with([
    'курьер' => [Role::Courier],
    'администратор' => [Role::Admin],
]);

it('требует токен для карточки', function (): void {
    $order = DeliveryOrder::factory()->create();

    $this->getJson('/api/v1/orders/'.$order->id)
        ->assertStatus(401)
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('читает список и карточку без ленивых загрузок', function (): void {
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->create(['sender_id' => $sender->id]);
    OrderStatusHistory::factory()->create(['order_id' => $order->id, 'changed_by' => $sender->id]);

    Sanctum::actingAs($sender);

    Model::preventLazyLoading();

    try {
        $this->getJson('/api/v1/orders')->assertOk();
        $this->getJson('/api/v1/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.history.0.changed_by.id', $sender->id);
    } finally {
        Model::preventLazyLoading(false);
    }
});
