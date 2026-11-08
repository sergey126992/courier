<?php

declare(strict_types=1);

use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Order\OrderStatus;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Заказ отправителя в заданном статусе.
 */
function cancelOrderFor(User $sender, OrderStatus $status): DeliveryOrder
{
    return DeliveryOrder::factory()->withStatus($status)->create(['sender_id' => $sender->id]);
}

/**
 * Адрес отмены заказа.
 */
function cancelUrl(DeliveryOrder $order): string
{
    return '/api/v1/orders/'.$order->id.'/cancel';
}

it('закрывает активное назначение вместе с заказом', function (AssignmentStatus $start): void {
    $sender = User::factory()->sender()->create();
    $order = cancelOrderFor($sender, OrderStatus::Assigned);

    $assignment = Assignment::factory()
        ->state(['status' => $start])
        ->create([
            'order_id' => $order->id,
            'courier_id' => User::factory()->courier()->create()->id,
        ]);

    Sanctum::actingAs($sender);

    $this->postJson(cancelUrl($order))
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);

    $assignment->refresh();

    expect($assignment->status)->toBe(AssignmentStatus::Cancelled)
        ->and($assignment->cancelled_at)->not->toBeNull();
})->with([[AssignmentStatus::Assigned], [AssignmentStatus::Accepted]]);

it('отменяет заказ без назначения как раньше', function (): void {
    $sender = User::factory()->sender()->create();
    $order = cancelOrderFor($sender, OrderStatus::Created);

    Sanctum::actingAs($sender);

    $this->postJson(cancelUrl($order))
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
    expect(Assignment::query()->count())->toBe(0);
});

it('не трогает завершённые назначения при отмене', function (): void {
    $sender = User::factory()->sender()->create();
    $order = cancelOrderFor($sender, OrderStatus::Assigned);

    $assignment = Assignment::factory()
        ->delivered()
        ->create([
            'order_id' => $order->id,
            'courier_id' => User::factory()->courier()->create()->id,
        ]);

    Sanctum::actingAs($sender);

    $this->postJson(cancelUrl($order))->assertOk();

    $assignment->refresh();

    expect($assignment->status)->toBe(AssignmentStatus::Delivered)
        ->and($assignment->cancelled_at)->toBeNull();
});
