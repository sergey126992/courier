<?php

declare(strict_types=1);

use App\Domain\Order\OrderStatus;
use App\Livewire\Cabinet\OrderDetails;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OrderStatusHistory;
use App\Models\User;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Карточка заказа и отмена (ЧТЗ 9.2, пункты 11–12)
|--------------------------------------------------------------------------
|
| FR-UI-126, 127: отмена до забора переводит заказ в «отменён» и пишет
| историю с автором и причиной; после забора кнопки нет, а прямой повтор
| даёт сообщение ORDER_STATUS_TRANSITION_NOT_ALLOWED без смены статуса.
|
*/

it('отменяет заказ и пишет историю с автором и причиной', function (): void {
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->create(['sender_id' => $sender->id]);

    $this->actingAs($sender);

    $component = Livewire::test(OrderDetails::class, ['orderId' => $order->id])
        ->assertSee(__('ui.actions.cancel_order'))
        ->set('cancelReason', 'Передумал')
        ->call('cancel')
        ->assertHasNoErrors()
        ->assertSet('status', __('orders.messages.cancelled'))
        ->assertDispatched('order-cancelled')
        ->assertDispatched('notify');

    expect($component->get('error'))->toBeNull()
        ->and($order->fresh()?->status)->toBe(OrderStatus::Cancelled);

    $this->assertDatabaseHas('order_status_history', [
        'order_id' => $order->id,
        'from_status' => OrderStatus::Created->value,
        'to_status' => OrderStatus::Cancelled->value,
        'changed_by' => $sender->id,
        'reason' => 'Передумал',
    ]);

    // После успеха кнопка отмены исчезает: карточка перечитала заказ.
    $component->assertDontSee(__('ui.actions.cancel_order'))
        ->assertSee(__('orders.messages.cancelled'));
});

it('отменяет заказ без причины', function (): void {
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->create(['sender_id' => $sender->id]);

    $this->actingAs($sender);

    Livewire::test(OrderDetails::class, ['orderId' => $order->id])
        ->call('cancel')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('order_status_history', [
        'order_id' => $order->id,
        'to_status' => OrderStatus::Cancelled->value,
        'changed_by' => $sender->id,
        'reason' => null,
    ]);
});

it('после забора показывает сообщение о недопустимом переходе и не меняет статус', function (): void {
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->withStatus(OrderStatus::PickedUp)->create([
        'sender_id' => $sender->id,
    ]);

    $this->actingAs($sender);

    $component = Livewire::test(OrderDetails::class, ['orderId' => $order->id])
        ->assertDontSee(__('ui.actions.cancel_order'))
        ->call('cancel');

    // Сообщение содержит текущий статус, а не только код ошибки (ЧТЗ 8.5).
    expect($component->get('error'))->toBeString()
        ->and($component->get('error'))->toContain(OrderStatus::PickedUp->label());

    expect($order->fresh()?->status)->toBe(OrderStatus::PickedUp);

    $this->assertDatabaseCount('order_status_history', 0);
});

it('не показывает кнопку отмены для статусов без перехода в «отменён»', function (OrderStatus $status): void {
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->withStatus($status)->create(['sender_id' => $sender->id]);

    $this->actingAs($sender);

    Livewire::test(OrderDetails::class, ['orderId' => $order->id])
        ->assertDontSee(__('ui.actions.cancel_order'));
})->with([
    'забран' => [OrderStatus::PickedUp],
    'вручён' => [OrderStatus::Delivered],
    'закрыт' => [OrderStatus::Closed],
    'отменён' => [OrderStatus::Cancelled],
    'не выполнен' => [OrderStatus::Failed],
]);

it('показывает кнопку отмены до забора', function (OrderStatus $status): void {
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->withStatus($status)->create(['sender_id' => $sender->id]);

    $this->actingAs($sender);

    Livewire::test(OrderDetails::class, ['orderId' => $order->id])
        ->assertSee(__('ui.actions.cancel_order'));
})->with([
    'создан' => [OrderStatus::Created],
    'назначен' => [OrderStatus::Assigned],
    'принят' => [OrderStatus::Accepted],
]);

it('показывает историю статусов с автором и причиной', function (): void {
    $sender = User::factory()->sender()->create();
    $dispatcher = User::factory()->dispatcher()->create();
    $order = DeliveryOrder::factory()->withStatus(OrderStatus::Cancelled)->create([
        'sender_id' => $sender->id,
    ]);

    OrderStatusHistory::factory()
        ->transition(OrderStatus::Created, OrderStatus::Cancelled, $dispatcher, 'Отменён диспетчером')
        ->create(['order_id' => $order->id]);

    $this->actingAs($sender);

    Livewire::test(OrderDetails::class, ['orderId' => $order->id])
        ->assertSee($dispatcher->name)
        ->assertSee('Отменён диспетчером')
        ->assertSee(__('orders.labels.history'));
});
