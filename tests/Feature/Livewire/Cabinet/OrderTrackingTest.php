<?php

declare(strict_types=1);

use App\Domain\Geography\Contracts\DistanceProvider;
use App\Domain\Geography\Exceptions\DistanceProviderUnavailable;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Order\OrderStatus;
use App\Livewire\Cabinet\OrderTracking;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use App\Models\Tracking\AssignmentLastPosition;
use App\Models\Tracking\TrackingPoint;
use App\Models\User;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Блок отслеживания заказа в кабинете (ЧТЗ 9.2, пункт 19)
|--------------------------------------------------------------------------
|
| FR-UI-128: статус, последняя позиция, зафиксированный ETA, остаток пути
| с источником и последние события. Недоступность провайдера расстояний
| даёт источник planned и не ломает страницу; refresh() перечитывает данные.
|
*/

/**
 * Заказ в работе с активным назначением, последней позицией и одной точкой ленты.
 *
 * @return array{0: DeliveryOrder, 1: User}
 */
function cabinetTrackingOrder(): array
{
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->withStatus(OrderStatus::PickedUp)->create([
        'sender_id' => $sender->id,
        'eta_minutes' => 22,
    ]);

    $courier = User::factory()->courier(TransportType::Light, LightVehicleKind::Bicycle)->create();

    $assignment = Assignment::factory()
        ->for($order, 'order')
        ->for($courier, 'courier')
        ->pickedUp()
        ->create();

    $recordedAt = now()->subMinutes(3);

    AssignmentLastPosition::factory()->create([
        'assignment_id' => $assignment->id,
        'latitude' => 55.760120,
        'longitude' => 37.618400,
        'recorded_at' => $recordedAt,
    ]);

    TrackingPoint::factory()
        ->recordedAt($recordedAt)
        ->at(55.760120, 37.618400)
        ->create(['assignment_id' => $assignment->id]);

    return [$order, $sender];
}

it('показывает источник planned при недоступном провайдере расстояний', function (): void {
    [$order, $sender] = cabinetTrackingOrder();

    app()->instance(DistanceProvider::class, new FakeDistanceProvider(
        4.5,
        DistanceProviderUnavailable::forProvider('fake', 'timeout'),
    ));

    $this->actingAs($sender);

    Livewire::test(OrderTracking::class, ['orderId' => $order->id])
        ->assertSee(__('orders.labels.tracking'))
        ->assertSee(__('tracking.labels.source_planned'))
        ->assertDontSee(__('tracking.labels.source_live'))
        // Последняя позиция и события остаются доступны и без остатка пути.
        ->assertSee('55,76012')
        ->assertSee(__('tracking.labels.events'));
});

it('показывает остаток пути и источник live, когда провайдер отвечает', function (): void {
    Carbon::setTestNow('2026-05-12 12:00:00');
    [$order, $sender] = cabinetTrackingOrder();

    app()->instance(DistanceProvider::class, new FakeDistanceProvider(4.5));

    $this->actingAs($sender);

    // «Велосипед»: 15 км/ч и 7 минут обработки; 4.5 км — это 18 минут движения.
    Livewire::test(OrderTracking::class, ['orderId' => $order->id])
        ->assertSee(__('tracking.labels.source_live'))
        ->assertDontSee(__('tracking.labels.source_planned'))
        ->assertSee(__('ui.create_order.minutes', ['minutes' => 25]));
});

it('перечитывает данные по действию refresh', function (): void {
    [$order, $sender] = cabinetTrackingOrder();

    $this->actingAs($sender);

    $component = Livewire::test(OrderTracking::class, ['orderId' => $order->id])
        ->assertSee(OrderStatus::PickedUp->label());

    $order->forceFill(['status' => OrderStatus::Delivered])->save();

    $component->call('refresh')
        ->assertSee(OrderStatus::Delivered->label())
        ->assertDontSee(OrderStatus::PickedUp->label());
});

it('объявляет автообновление по интервалу из конфигурации', function (): void {
    [$order, $sender] = cabinetTrackingOrder();

    $this->actingAs($sender);

    $seconds = (int) config('ui.tracking.refresh_seconds');

    Livewire::test(OrderTracking::class, ['orderId' => $order->id])
        ->assertSet('refreshSeconds', $seconds)
        ->assertSeeHtml('wire:poll.'.$seconds.'s="refresh"');
});

it('показывает пустое состояние событий, когда ленты ещё нет', function (): void {
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->create(['sender_id' => $sender->id]);

    $this->actingAs($sender);

    Livewire::test(OrderTracking::class, ['orderId' => $order->id])
        ->assertSee(__('tracking.labels.no_position'))
        ->assertSee(__('ui.states.empty_tracking_title'));
});
