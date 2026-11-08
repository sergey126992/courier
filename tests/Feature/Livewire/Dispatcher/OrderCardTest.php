<?php

declare(strict_types=1);

use App\Domain\Dispatch\AssignmentStrategy;
use App\Domain\Geography\Contracts\DistanceProvider;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Order\OrderStatus;
use App\Livewire\Dispatcher\OrderCard;
use App\Models\Dispatch\CourierShift;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OrderNotificationStatus;
use App\Models\Tracking\CourierLastPosition;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Карточка заказа диспетчера (ЧТЗ 9.2, пункты 15–17)
|--------------------------------------------------------------------------
|
| FR-UI-134…138: кандидаты показываются с фактически применённым правилом,
| пустой список — штатное состояние, назначение меняет курьера и пишет
| событие, конфликт двух диспетчеров показывается сообщением с кодом и не
| ломает страницу, переназначение работает из статуса «не выполнен».
|
*/

/**
 * Предсказуемый провайдер расстояний: любая пара точек — 12.5 км.
 */
function orderCardUseDistanceProvider(): void
{
    app()->instance(DistanceProvider::class, new FakeDistanceProvider(12.5));
}

/**
 * Заказ в статусе «создан» с параметрами, проходящими по лёгкому транспорту.
 *
 * @param array<string, mixed> $attributes
 */
function orderCardOrder(array $attributes = []): DeliveryOrder
{
    return DeliveryOrder::factory()->create(array_merge([
        'transport_type' => TransportType::Light,
        'light_vehicle_kind' => LightVehicleKind::Bicycle,
        'parcel' => [
            'weight_grams' => 1500,
            'length_cm' => 30,
            'width_cm' => 20,
            'height_cm' => 10,
        ],
        'distance_km' => 1.8,
        'eta_minutes' => 15,
    ], $attributes));
}

/**
 * Доступный курьер: активный профиль, открытая смена и известная позиция.
 */
function orderCardCourier(): User
{
    $courier = User::factory()->courier(TransportType::Light, LightVehicleKind::Bicycle)->create();

    CourierShift::factory()->open()->for($courier, 'courier')->create();

    CourierLastPosition::factory()->for($courier, 'courier')->create([
        'latitude' => 55.755826,
        'longitude' => 37.6173,
    ]);

    return $courier;
}

it('показывает кандидатов с фактически применённым правилом', function (): void {
    config(['dispatch.strategy' => AssignmentStrategy::Nearest->value]);

    orderCardUseDistanceProvider();

    $dispatcher = User::factory()->dispatcher()->create();
    $order = orderCardOrder();
    $courier = orderCardCourier();

    $this->actingAs($dispatcher);

    $component = Livewire::test(OrderCard::class, ['orderId' => $order->id])
        ->assertSee(__('ui.actions.show_candidates'))
        ->call('loadCandidates')
        ->assertHasNoErrors()
        ->assertSet('appliedStrategy', AssignmentStrategy::Nearest->value)
        ->assertSee(AssignmentStrategy::Nearest->label())
        ->assertSee($courier->name)
        ->assertSee(__('dispatch.labels.distance_to_pickup'))
        ->assertSee(__('dispatch.labels.assignments_per_shift'))
        ->assertSee(__('dispatch.labels.approach_time'));

    expect($component->get('candidates'))->toHaveCount(1)
        ->and($component->get('selectedCourierId'))->toBe($courier->id);

    // Выбранное в интерфейсе правило применяется и показывается как фактическое (FR-UI-135).
    $component->set('strategy', AssignmentStrategy::LeastLoaded->value)
        ->call('loadCandidates')
        ->assertSet('appliedStrategy', AssignmentStrategy::LeastLoaded->value)
        ->assertSee(AssignmentStrategy::LeastLoaded->label());
});

it('помечает отсутствие позиции кандидата явно', function (): void {
    orderCardUseDistanceProvider();

    $dispatcher = User::factory()->dispatcher()->create();
    $order = orderCardOrder();

    $courier = User::factory()->courier(TransportType::Light, LightVehicleKind::Bicycle)->create();
    CourierShift::factory()->open()->for($courier, 'courier')->create();

    $this->actingAs($dispatcher);

    Livewire::test(OrderCard::class, ['orderId' => $order->id])
        ->call('loadCandidates')
        ->assertSee($courier->name)
        ->assertSee(__('dispatch.labels.no_position'));
});

it('показывает пустой список кандидатов штатным состоянием, а не ошибкой', function (): void {
    orderCardUseDistanceProvider();

    $dispatcher = User::factory()->dispatcher()->create();
    $order = orderCardOrder();

    $this->actingAs($dispatcher);

    $component = Livewire::test(OrderCard::class, ['orderId' => $order->id])
        ->call('loadCandidates')
        ->assertHasNoErrors()
        ->assertSee(__('ui.states.empty_candidates_title'))
        ->assertSee(__('ui.states.empty_candidates_description'))
        ->assertSee(__('ui.actions.refresh'));

    expect($component->get('error'))->toBeNull()
        ->and($component->get('candidates'))->toBe([])
        ->and($component->get('candidatesLoaded'))->toBeTrue();
});

it('назначает выбранного курьера, обновляет карточку и сообщает имя', function (): void {
    orderCardUseDistanceProvider();

    $dispatcher = User::factory()->dispatcher()->create();
    $order = orderCardOrder();
    $courier = orderCardCourier();

    $this->actingAs($dispatcher);

    $component = Livewire::test(OrderCard::class, ['orderId' => $order->id])
        ->call('loadCandidates')
        ->set('selectedCourierId', $courier->id)
        ->call('assign')
        ->assertHasNoErrors()
        ->assertDispatched('assignment-created')
        ->assertDispatched('notify');

    expect($component->get('error'))->toBeNull()
        ->and($component->get('status'))->toBe((string) __('dispatch.messages.assigned', ['name' => $courier->name]))
        ->and($order->fresh()?->status)->toBe(OrderStatus::Assigned);

    $component->assertSee(__('dispatch.messages.assigned', ['name' => $courier->name]));

    $this->assertDatabaseHas('assignments', [
        'order_id' => $order->id,
        'courier_id' => $courier->id,
    ]);
});

it('сообщает об автоматическом подборе без кандидатов и сохраняет страницу рабочей', function (): void {
    orderCardUseDistanceProvider();

    $dispatcher = User::factory()->dispatcher()->create();
    $order = orderCardOrder();

    $this->actingAs($dispatcher);

    $component = Livewire::test(OrderCard::class, ['orderId' => $order->id])
        ->call('assign');

    expect($component->get('error'))->toContain('NO_COURIER_AVAILABLE')
        ->and($order->fresh()?->status)->toBe(OrderStatus::Created);

    $component->assertSee($order->number);
});

it('показывает конфликт второго диспетчера сообщением и сохраняет выбор', function (): void {
    orderCardUseDistanceProvider();

    $first = User::factory()->dispatcher()->create();
    $second = User::factory()->dispatcher()->create();
    $order = orderCardOrder();
    $courier = orderCardCourier();

    // Первый диспетчер успел назначить курьера раньше.
    $this->actingAs($first);

    Livewire::test(OrderCard::class, ['orderId' => $order->id])
        ->set('selectedCourierId', $courier->id)
        ->call('assign')
        ->assertHasNoErrors();

    expect($order->fresh()?->status)->toBe(OrderStatus::Assigned);

    $this->actingAs($second);

    $component = Livewire::test(OrderCard::class, ['orderId' => $order->id])
        ->set('selectedCourierId', $courier->id)
        ->call('assign')
        ->assertHasNoErrors();

    expect($component->get('error'))->toContain('ORDER_STATUS_TRANSITION_NOT_ALLOWED')
        // Выбор пользователя не теряется при отказе (FR-UI-138).
        ->and($component->get('selectedCourierId'))->toBe($courier->id);

    // Страница остаётся рабочей: виден номер заказа и текущий статус.
    $component->assertSee($order->number)
        ->assertSee(OrderStatus::Assigned->label());
});

it('переназначает курьера из статуса «не выполнен»', function (): void {
    orderCardUseDistanceProvider();

    $dispatcher = User::factory()->dispatcher()->create();
    $order = DeliveryOrder::factory()->withStatus(OrderStatus::Failed)->create([
        'transport_type' => TransportType::Light,
        'light_vehicle_kind' => LightVehicleKind::Bicycle,
        'parcel' => [
            'weight_grams' => 1500,
            'length_cm' => 30,
            'width_cm' => 20,
            'height_cm' => 10,
        ],
        'distance_km' => 1.8,
        'eta_minutes' => 15,
    ]);
    $courier = orderCardCourier();

    $this->actingAs($dispatcher);

    $component = Livewire::test(OrderCard::class, ['orderId' => $order->id])
        ->assertSee(__('ui.actions.reassign'))
        ->set('selectedCourierId', $courier->id)
        ->call('reassign')
        ->assertHasNoErrors()
        ->assertDispatched('assignment-created');

    expect($component->get('status'))->toBe((string) __('dispatch.messages.reassigned', ['name' => $courier->name]))
        ->and($order->fresh()?->status)->toBe(OrderStatus::Assigned);

    $this->assertDatabaseHas('assignments', [
        'order_id' => $order->id,
        'courier_id' => $courier->id,
    ]);
});

it('укладывает рендер блока кандидатов в пять запросов', function (): void {
    orderCardUseDistanceProvider();

    $dispatcher = User::factory()->dispatcher()->create();
    $order = orderCardOrder();
    orderCardCourier();

    $this->actingAs($dispatcher);

    $component = Livewire::test(OrderCard::class, ['orderId' => $order->id])->call('loadCandidates');

    $selects = 0;

    DB::listen(static function (QueryExecuted $query) use (&$selects): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $selects++;
        }
    });

    $component->call('$refresh')->assertSee(__('dispatch.labels.candidates'));

    expect($selects)->toBeLessThanOrEqual(5);
});

it('показывает статус доставки уведомлений из проекции сервиса', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();
    $order = orderCardOrder();

    OrderNotificationStatus::query()->create([
        'order_id' => $order->id,
        'notification_id' => 501,
        'channel' => 'email',
        'status' => 'sent',
        'attempt' => 1,
        'source_event_id' => (string) Str::uuid(),
        'occurred_at' => now(),
    ]);

    OrderNotificationStatus::query()->create([
        'order_id' => $order->id,
        'notification_id' => 502,
        'channel' => 'push',
        'status' => 'failed',
        'attempt' => 5,
        'error_code' => 'NOTIFICATION_SEND_FAILED',
        'source_event_id' => (string) Str::uuid(),
        'occurred_at' => now(),
    ]);

    $this->actingAs($dispatcher);

    Livewire::test(OrderCard::class, ['orderId' => $order->id])
        ->assertSee(__('orders.labels.notifications'))
        ->assertSee(__('orders.labels.notification_channel_email'))
        ->assertSee(__('orders.labels.notification_status_sent'))
        ->assertSee(__('orders.labels.notification_channel_push'))
        ->assertSee(__('orders.labels.notification_status_failed'));
});

it('показывает по каналу последний статус, а не каждое уведомление', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();
    $order = orderCardOrder();

    foreach ([[701, 'sent'], [702, 'failed']] as [$notificationId, $status]) {
        OrderNotificationStatus::query()->create([
            'order_id' => $order->id,
            'notification_id' => $notificationId,
            'channel' => 'email',
            'status' => $status,
            'attempt' => 1,
            'source_event_id' => (string) Str::uuid(),
            'occurred_at' => now(),
        ]);
    }

    $this->actingAs($dispatcher);

    /** @var list<array{channel: string, status: string, state: string}> $items */
    $items = Livewire::test(OrderCard::class, ['orderId' => $order->id])->viewData('notifications');

    expect($items)->toHaveCount(1)
        ->and($items[0]['channel'])->toBe(__('orders.labels.notification_channel_email'))
        ->and($items[0]['state'])->toBe('failed');
});

it('не показывает уведомления другого заказа в карточке', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();
    $order = orderCardOrder();
    $other = orderCardOrder();

    // Единственный источник статуса — проекция обратных событий (FR-NTF-405).
    OrderNotificationStatus::query()->create([
        'order_id' => $other->id,
        'notification_id' => 601,
        'channel' => 'push',
        'status' => 'failed',
        'attempt' => 5,
        'error_code' => 'OTHER_ORDER',
        'source_event_id' => (string) Str::uuid(),
        'occurred_at' => now(),
    ]);

    $this->actingAs($dispatcher);

    Livewire::test(OrderCard::class, ['orderId' => $order->id])
        ->assertSee(__('orders.labels.notifications'))
        ->assertSee(__('orders.labels.notifications_empty'))
        ->assertDontSee('OTHER_ORDER');
});

it('показывает нейтральное состояние без данных о доставке уведомлений', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();
    $order = orderCardOrder();

    $this->actingAs($dispatcher);

    Livewire::test(OrderCard::class, ['orderId' => $order->id])
        ->assertSee(__('orders.labels.notifications'))
        ->assertSee(__('orders.labels.notifications_empty'));
});
