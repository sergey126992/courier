<?php

declare(strict_types=1);

use App\Domain\Order\OrderStatus;
use App\Livewire\Cabinet\OrderList;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use App\Services\Order\OrderListQuery;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Список заказов отправителя (ЧТЗ 9.2, пункты 9–10)
|--------------------------------------------------------------------------
|
| FR-UI-124, 125, 182: фильтры меняют выборку и живут в адресе страницы,
| «Показать ещё» добавляет следующую порцию без дублей, пустая выборка
| объясняет причину и предлагает сбросить фильтры.
|
*/

it('показывает только заказы текущего отправителя, новые сверху', function (): void {
    $sender = User::factory()->sender()->create();
    $other = User::factory()->sender()->create();

    $older = DeliveryOrder::factory()->create([
        'sender_id' => $sender->id,
        'created_at' => now()->subDays(2),
    ]);
    $newer = DeliveryOrder::factory()->create([
        'sender_id' => $sender->id,
        'created_at' => now()->subDay(),
    ]);
    DeliveryOrder::factory()->create(['sender_id' => $other->id]);

    $this->actingAs($sender);

    $component = Livewire::test(OrderList::class);

    expect(array_column($component->get('items'), 'number'))
        ->toBe([$newer->number, $older->number]);
});

it('фильтрует выборку по статусу и периоду создания', function (): void {
    $sender = User::factory()->sender()->create();

    $delivered = DeliveryOrder::factory()->withStatus(OrderStatus::Delivered)->create([
        'sender_id' => $sender->id,
        'created_at' => now()->subDay(),
    ]);
    DeliveryOrder::factory()->withStatus(OrderStatus::Created)->create([
        'sender_id' => $sender->id,
        'created_at' => now()->subDays(10),
    ]);

    $this->actingAs($sender);

    $component = Livewire::test(OrderList::class)
        ->set('statuses', [OrderStatus::Delivered->value])
        ->call('applyFilters');

    expect(array_column($component->get('items'), 'number'))->toBe([$delivered->number]);

    // Период отсекает старый заказ так же, как и статус.
    $component->call('resetFilters')
        ->set('from', now()->subDays(3)->toDateString())
        ->call('applyFilters');

    expect(array_column($component->get('items'), 'number'))->toBe([$delivered->number]);
});

it('восстанавливает фильтры из адреса страницы', function (): void {
    $sender = User::factory()->sender()->create();

    $delivered = DeliveryOrder::factory()->withStatus(OrderStatus::Delivered)->create([
        'sender_id' => $sender->id,
    ]);
    DeliveryOrder::factory()->withStatus(OrderStatus::Created)->create([
        'sender_id' => $sender->id,
    ]);

    $this->actingAs($sender);

    $component = Livewire::withQueryParams(['status' => [OrderStatus::Delivered->value]])
        ->test(OrderList::class);

    expect($component->get('statuses'))->toBe([OrderStatus::Delivered->value])
        ->and(array_column($component->get('items'), 'number'))->toBe([$delivered->number]);
});

it('добавляет следующую порцию без дублей и пропусков', function (): void {
    config(['ui.pagination.per_page' => 2]);

    $sender = User::factory()->sender()->create();

    $orders = collect(range(0, 4))->map(fn (int $index): DeliveryOrder => DeliveryOrder::factory()->create([
        'sender_id' => $sender->id,
        'created_at' => now()->subMinutes($index),
    ]));

    $this->actingAs($sender);

    $component = Livewire::test(OrderList::class);

    expect($component->get('items'))->toHaveCount(2)
        ->and($component->get('hasMore'))->toBeTrue();

    // «Показать ещё» — компонент pagination с действием Livewire и запасным адресом.
    $component->assertSee(__('ui.actions.show_more'))
        ->assertSeeHtml('wire:click.prevent="loadMore"')
        ->assertSeeHtml('rel="next"');

    $component->call('loadMore');

    expect($component->get('items'))->toHaveCount(4);

    $component->call('loadMore');

    $numbers = array_column($component->get('items'), 'number');

    expect($numbers)->toHaveCount(5)
        ->and(array_unique($numbers))->toHaveCount(5)
        ->and($numbers)->toBe($orders->pluck('number')->all())
        ->and($component->get('hasMore'))->toBeFalse();
});

it('ведёт из списка в карточку заказа', function (): void {
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->create(['sender_id' => $sender->id]);

    $this->actingAs($sender);

    Livewire::test(OrderList::class)
        // Ссылка настоящая, поэтому работает и с клавиатуры; имя — номер заказа.
        ->assertSeeHtml('href="'.route('cabinet.orders.show', $order).'"')
        ->assertSee($order->number)
        ->assertSee(__('ui.actions.open_order'));
});

it('показывает пустое состояние с причиной и сбросом фильтров', function (): void {
    $sender = User::factory()->sender()->create();

    $this->actingAs($sender);

    Livewire::test(OrderList::class)
        ->assertSee(__('ui.states.empty_orders_title'))
        ->assertSee(__('ui.states.empty_orders_description'));

    DeliveryOrder::factory()->create(['sender_id' => $sender->id]);

    Livewire::test(OrderList::class)
        ->set('statuses', [OrderStatus::Cancelled->value])
        ->call('applyFilters')
        ->assertSee(__('ui.states.empty_filtered_title'))
        ->assertSee(__('ui.states.empty_filtered_description'))
        ->assertSee(__('ui.actions.reset_filters'));
});

it('сбрасывает фильтры и возвращает полную выдачу', function (): void {
    $sender = User::factory()->sender()->create();

    DeliveryOrder::factory()->withStatus(OrderStatus::Delivered)->create(['sender_id' => $sender->id]);
    DeliveryOrder::factory()->withStatus(OrderStatus::Created)->create(['sender_id' => $sender->id]);

    $this->actingAs($sender);

    $component = Livewire::test(OrderList::class)
        ->set('statuses', [OrderStatus::Delivered->value])
        ->call('applyFilters')
        ->assertSet('statuses', [OrderStatus::Delivered->value]);

    expect($component->get('items'))->toHaveCount(1);

    $component->call('resetFilters')
        ->assertSet('statuses', [])
        ->assertSet('from', null)
        ->assertSet('to', null);

    expect($component->get('items'))->toHaveCount(2);
});

it('открывает выдачу с курсора из адреса без JavaScript', function (): void {
    config(['ui.pagination.per_page' => 2]);

    $sender = User::factory()->sender()->create();

    $orders = collect(range(0, 3))->map(fn (int $index): DeliveryOrder => DeliveryOrder::factory()->create([
        'sender_id' => $sender->id,
        'created_at' => now()->subMinutes($index),
    ]));

    $query = app(OrderListQuery::class);
    $cursor = $query->paginate($query->query($sender), 2)->nextCursor()?->encode();

    $this->actingAs($sender);

    $component = Livewire::withQueryParams(['cursor' => $cursor])->test(OrderList::class);

    expect(array_column($component->get('items'), 'number'))
        ->toBe($orders->slice(2, 2)->pluck('number')->values()->all());
});
