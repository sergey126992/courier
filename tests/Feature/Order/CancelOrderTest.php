<?php

declare(strict_types=1);

use App\Domain\Order\OrderStatus;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Заказ указанного отправителя в заданном статусе.
 */
function orderForSender(User $sender, OrderStatus $status = OrderStatus::Created): DeliveryOrder
{
    return DeliveryOrder::factory()->withStatus($status)->create(['sender_id' => $sender->id]);
}

it('отменяет свой заказ отправителем и пишет историю с автором и причиной', function (): void {
    $sender = User::factory()->sender()->create();
    Sanctum::actingAs($sender);
    $order = orderForSender($sender);

    $response = $this->postJson('/api/v1/orders/'.$order->id.'/cancel', ['reason' => 'Передумал']);

    $response->assertOk()->assertJsonPath('data.status', 'cancelled');

    $this->assertDatabaseHas('delivery_orders', [
        'id' => $order->id,
        'status' => 'cancelled',
    ]);

    $this->assertDatabaseHas('order_status_history', [
        'order_id' => $order->id,
        'from_status' => 'created',
        'to_status' => 'cancelled',
        'changed_by' => $sender->id,
        'reason' => 'Передумал',
    ]);

    expect($order->fresh()?->cancelled_at)->not->toBeNull();
});

it('позволяет диспетчеру отменить чужой заказ без согласия отправителя', function (): void {
    $sender = User::factory()->sender()->create();
    $order = orderForSender($sender);

    $dispatcher = User::factory()->dispatcher()->create();
    Sanctum::actingAs($dispatcher);

    $this->postJson('/api/v1/orders/'.$order->id.'/cancel')->assertOk();

    $this->assertDatabaseHas('order_status_history', [
        'order_id' => $order->id,
        'to_status' => 'cancelled',
        'changed_by' => $dispatcher->id,
    ]);
});

it('запрещает отмену после забора посылки', function (): void {
    $sender = User::factory()->sender()->create();
    Sanctum::actingAs($sender);
    $order = orderForSender($sender, OrderStatus::PickedUp);

    $response = $this->postJson('/api/v1/orders/'.$order->id.'/cancel');

    $response->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'ORDER_STATUS_TRANSITION_NOT_ALLOWED')
        ->assertJsonPath('details.0.field', 'status');

    expect((string) $response->json('details.0.message'))->toContain('Забран');

    $this->assertDatabaseHas('delivery_orders', ['id' => $order->id, 'status' => 'picked_up']);
});

it('запрещает повторную отмену', function (): void {
    $sender = User::factory()->sender()->create();
    Sanctum::actingAs($sender);
    $order = orderForSender($sender);

    $this->postJson('/api/v1/orders/'.$order->id.'/cancel')->assertOk();

    $this->postJson('/api/v1/orders/'.$order->id.'/cancel')
        ->assertStatus(409)
        ->assertJsonPath('code', 'ORDER_STATUS_TRANSITION_NOT_ALLOWED');
});

it('не раскрывает чужой заказ: отправитель получает 404', function (): void {
    $owner = User::factory()->sender()->create();
    $order = orderForSender($owner);

    Sanctum::actingAs(User::factory()->sender()->create());

    $this->postJson('/api/v1/orders/'.$order->id.'/cancel')
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    $this->assertDatabaseHas('delivery_orders', ['id' => $order->id, 'status' => 'created']);
});

it('не даёт курьеру отменить заказ', function (): void {
    $sender = User::factory()->sender()->create();
    $order = orderForSender($sender);

    Sanctum::actingAs(User::factory()->courier()->create());

    $this->postJson('/api/v1/orders/'.$order->id.'/cancel')->assertStatus(404);
});

it('требует токен для отмены', function (): void {
    $order = orderForSender(User::factory()->sender()->create());

    $this->postJson('/api/v1/orders/'.$order->id.'/cancel')
        ->assertStatus(401)
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('отдаёт 404 на несуществующий заказ', function (): void {
    Sanctum::actingAs(User::factory()->sender()->create());

    $this->postJson('/api/v1/orders/999999/cancel')
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');
});
