<?php

declare(strict_types=1);

use App\Domain\Identity\TransportType;
use App\Domain\Order\OrderStatus;
use App\Livewire\Dispatcher\OrderQueue;
use App\Models\Geography\ServiceZone;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Очередь заказов диспетчера (ЧТЗ 9.2, пункты 13–14)
|--------------------------------------------------------------------------
|
| FR-UI-131…133, 182: очередь содержит только заказы «создан», новые сверху,
| фильтр зоны меняет выборку, пауза останавливает автообновление и видна
| текстом, пустая очередь объясняет причину, рендер — не более трёх SELECT.
|
*/

it('показывает только заказы в статусе «создан», новые сверху', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();

    $older = DeliveryOrder::factory()->create(['created_at' => now()->subDays(2)]);
    $newer = DeliveryOrder::factory()->create(['created_at' => now()->subDay()]);
    DeliveryOrder::factory()->withStatus(OrderStatus::Cancelled)->create();

    $this->actingAs($dispatcher);

    $component = Livewire::test(OrderQueue::class);

    expect(array_column($component->get('items'), 'number'))
        ->toBe([$newer->number, $older->number]);
});

it('фильтрует очередь по зоне и сохраняет фильтр при обновлении', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();
    $zone = ServiceZone::factory()->create(['name' => 'Центр']);

    $inZone = DeliveryOrder::factory()->create(['zone_id' => $zone->id]);
    DeliveryOrder::factory()->create();

    $this->actingAs($dispatcher);

    $component = Livewire::test(OrderQueue::class)
        ->set('zoneId', $zone->id)
        ->call('applyFilters');

    expect(array_column($component->get('items'), 'number'))->toBe([$inZone->number]);

    // Автообновление не сбрасывает фильтры (FR-UI-133).
    $component->call('refresh')
        ->assertSet('zoneId', $zone->id)
        ->assertSee($zone->name);

    expect(array_column($component->get('items'), 'number'))->toBe([$inZone->number]);
});

it('фильтрует очередь по типу транспорта', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();

    $car = DeliveryOrder::factory()->create([
        'transport_type' => TransportType::Car,
        'light_vehicle_kind' => null,
    ]);
    DeliveryOrder::factory()->create();

    $this->actingAs($dispatcher);

    $component = Livewire::test(OrderQueue::class)
        ->set('transport', TransportType::Car->value)
        ->call('applyFilters');

    expect(array_column($component->get('items'), 'number'))->toBe([$car->number]);
});

it('останавливает автообновление по кнопке паузы и сохраняет состояние', function (): void {
    config(['ui.dispatcher.refresh_seconds' => 7]);

    $dispatcher = User::factory()->dispatcher()->create();
    DeliveryOrder::factory()->count(2)->create();

    $this->actingAs($dispatcher);

    $component = Livewire::test(OrderQueue::class)
        ->assertSeeHtml('wire:poll.7s="refresh"');

    expect($component->get('items'))->toHaveCount(2);

    $component->call('togglePause')
        ->assertSet('paused', true)
        ->assertSee(__('dispatch.labels.paused'))
        // Пока стоит пауза, атрибут poll не выводится: запросов нет.
        ->assertDontSeeHtml('wire:poll');

    DeliveryOrder::factory()->create();

    $component->call('refresh');

    expect($component->get('items'))->toHaveCount(2);

    $component->call('togglePause')
        ->assertSet('paused', false)
        ->assertSee(__('dispatch.labels.active'))
        ->call('refresh');

    expect($component->get('items'))->toHaveCount(3);
});

it('показывает пустую очередь с причиной и действием «Обновить»', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();

    $this->actingAs($dispatcher);

    $component = Livewire::test(OrderQueue::class)
        ->assertSee(__('ui.states.empty_queue_title'))
        ->assertSee(__('ui.states.empty_queue_description'))
        ->assertSee(__('ui.actions.refresh'));

    DeliveryOrder::factory()->create();

    $component->call('applyFilters');

    expect($component->get('items'))->toHaveCount(1);
});

it('объявляет количество заказов через aria-live', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();
    DeliveryOrder::factory()->count(2)->create();

    $this->actingAs($dispatcher);

    Livewire::test(OrderQueue::class)
        ->assertSeeHtml('role="status"')
        ->assertSee((string) __('dispatch.labels.order_count', ['count' => 2]));
});

it('укладывает рендер очереди в три запроса', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();
    DeliveryOrder::factory()->count(3)->create();

    $this->actingAs($dispatcher);

    $selects = 0;

    DB::listen(static function (QueryExecuted $query) use (&$selects): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $selects++;
        }
    });

    Livewire::test(OrderQueue::class);

    expect($selects)->toBeLessThanOrEqual(3);
});
