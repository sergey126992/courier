<?php

declare(strict_types=1);

use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Order\OrderStatus;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OrderStatusHistory;
use App\Models\Order\OutboxMessage;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Заказ отправителя в заданном статусе.
 */
function cancelledEventOrder(User $sender, OrderStatus $status): DeliveryOrder
{
    return DeliveryOrder::factory()->withStatus($status)->create(['sender_id' => $sender->id]);
}

it('пишет order.cancelled одной транзакцией с историей и закрытием назначения', function (): void {
    $sender = User::factory()->sender()->create();
    $order = cancelledEventOrder($sender, OrderStatus::Assigned);

    $assignment = Assignment::factory()
        ->state(['status' => AssignmentStatus::Assigned])
        ->create([
            'order_id' => $order->id,
            'courier_id' => User::factory()->courier()->create()->id,
        ]);

    Sanctum::actingAs($sender);

    $this->postJson('/api/v1/orders/'.$order->id.'/cancel', ['reason' => 'client_request'])->assertOk();

    $event = OutboxMessage::query()->where('event_type', 'order.cancelled')->sole();

    expect($event->aggregate_type)->toBe('order')
        ->and($event->aggregate_id)->toBe($order->id)
        ->and($event->payload)->toHaveKeys([
            'order_id',
            'sender_id',
            'cancelled_by',
            'cancelled_by_role',
            'status',
            'cancelled_at',
            'reason_code',
        ])
        ->and($event->payload)->toMatchArray([
            'order_id' => $order->id,
            'sender_id' => $sender->id,
            'cancelled_by' => $sender->id,
            'cancelled_by_role' => 'sender',
            'status' => 'cancelled',
            'reason_code' => 'client_request',
        ])
        ->and($event->payload['cancelled_at'])->toBeString();

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($assignment->refresh()->status)->toBe(AssignmentStatus::Cancelled);

    $history = OrderStatusHistory::query()->where('order_id', $order->id)->sole();

    expect($history->from_status)->toBe(OrderStatus::Assigned)
        ->and($history->to_status)->toBe(OrderStatus::Cancelled)
        ->and($history->changed_by)->toBe($sender->id)
        ->and($history->reason)->toBe('client_request');
});

it('пишет событие отмены и без активного назначения', function (): void {
    $sender = User::factory()->sender()->create();
    $order = cancelledEventOrder($sender, OrderStatus::Created);

    Sanctum::actingAs($sender);

    $this->postJson('/api/v1/orders/'.$order->id.'/cancel')->assertOk();

    $event = OutboxMessage::query()->where('event_type', 'order.cancelled')->sole();

    expect($event->aggregate_id)->toBe($order->id)
        ->and($event->payload['reason_code'])->toBeNull()
        ->and($event->payload['status'])->toBe('cancelled')
        ->and(Assignment::query()->count())->toBe(0)
        ->and(OrderStatusHistory::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('указывает роль автора-диспетчера', function (): void {
    $sender = User::factory()->sender()->create();
    $order = cancelledEventOrder($sender, OrderStatus::Created);

    $dispatcher = User::factory()->dispatcher()->create();
    Sanctum::actingAs($dispatcher);

    $this->postJson('/api/v1/orders/'.$order->id.'/cancel', ['reason' => 'dispatcher_decision'])->assertOk();

    $event = OutboxMessage::query()->where('event_type', 'order.cancelled')->sole();

    expect($event->payload['cancelled_by'])->toBe($dispatcher->id)
        ->and($event->payload['cancelled_by_role'])->toBe('dispatcher')
        ->and($event->payload['sender_id'])->toBe($sender->id);
});
