<?php

declare(strict_types=1);

use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Dispatch\AssignmentStrategy;
use App\Domain\Geography\Contracts\DistanceProvider;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Order\OrderStatus;
use App\Models\Dispatch\Assignment;
use App\Models\Dispatch\CourierShift;
use App\Models\Geography\ServiceZone;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OrderStatusHistory;
use App\Models\Order\OutboxMessage;
use App\Models\Tracking\CourierLastPosition;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Диспетчер с действующим токеном.
 */
function assignAsDispatcher(): User
{
    $dispatcher = User::factory()->dispatcher()->create();
    Sanctum::actingAs($dispatcher);

    return $dispatcher;
}

/**
 * Предсказуемый провайдер расстояний: любая пара точек — 12.5 км.
 */
function assignUseDistanceProvider(): void
{
    app()->instance(DistanceProvider::class, new FakeDistanceProvider(12.5));
}

/**
 * Заказ в статусе «создан» с параметрами, проходящими по лёгкому транспорту.
 *
 * @param array<string, mixed> $attributes
 */
function assignOrder(array $attributes = []): DeliveryOrder
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
 *
 * @param bool $withShift открытая смена: без неё курьер недоступен
 */
function assignCourier(
    ?ServiceZone $zone = null,
    bool $withShift = true,
    TransportType $transport = TransportType::Light,
    ?LightVehicleKind $kind = LightVehicleKind::Bicycle,
): User {
    $courier = User::factory()->courier($transport, $kind)->create();

    if ($withShift) {
        CourierShift::factory()->open()->for($courier, 'courier')->create(['zone_id' => $zone?->id]);
    }

    CourierLastPosition::factory()->for($courier, 'courier')->create([
        'latitude' => 55.755826,
        'longitude' => 37.6173,
    ]);

    return $courier;
}

/**
 * Адрес назначения заказа.
 */
function assignUrl(DeliveryOrder $order): string
{
    return '/api/v1/orders/'.$order->id.'/assign';
}

it('назначает курьера автоматически и пишет историю и событие', function (): void {
    assignUseDistanceProvider();
    $dispatcher = assignAsDispatcher();
    $order = assignOrder();
    $courier = assignCourier();

    $this->postJson(assignUrl($order))
        ->assertOk()
        ->assertJsonPath('data.assignment.status', 'assigned')
        ->assertJsonPath('data.assignment.status_label', 'Назначено')
        ->assertJsonPath('data.assignment.strategy', 'nearest')
        ->assertJsonPath('data.assignment.distance_to_pickup_km', 12.5)
        ->assertJsonPath('data.assignment.courier.id', $courier->id)
        ->assertJsonPath('data.assignment.courier.transport', 'light')
        ->assertJsonPath('data.assignment.courier.light_vehicle_kind', 'bicycle')
        ->assertJsonPath('data.order.number', $order->number)
        ->assertJsonPath('data.order.status', 'assigned')
        ->assertJsonPath('data.order.status_label', 'Назначен');

    $assignment = Assignment::query()->sole();

    expect($assignment->courier_id)->toBe($courier->id)
        ->and($assignment->order_id)->toBe($order->id)
        ->and($assignment->status)->toBe(AssignmentStatus::Assigned)
        ->and($assignment->strategy)->toBe(AssignmentStrategy::Nearest)
        ->and($assignment->distance_to_pickup_km)->toBe(12.5)
        ->and($assignment->assigned_at)->not->toBeNull();

    expect($order->refresh()->status)->toBe(OrderStatus::Assigned);

    $history = OrderStatusHistory::query()->where('order_id', $order->id)->sole();

    expect($history->from_status)->toBe(OrderStatus::Created)
        ->and($history->to_status)->toBe(OrderStatus::Assigned)
        ->and($history->changed_by)->toBe($dispatcher->id);

    $event = OutboxMessage::query()->where('event_type', 'order.assigned')->sole();

    expect($event->aggregate_type)->toBe('order')
        ->and($event->aggregate_id)->toBe($order->id)
        ->and($event->payload)->toMatchArray([
            'order_id' => $order->id,
            'assignment_id' => $assignment->id,
            'courier_id' => $courier->id,
            'status' => 'assigned',
        ]);
});

it('назначает указанного курьера вручную', function (): void {
    assignUseDistanceProvider();
    assignAsDispatcher();
    $order = assignOrder();
    $chosen = assignCourier();
    assignCourier();

    $this->postJson(assignUrl($order), ['courier_id' => $chosen->id])
        ->assertOk()
        ->assertJsonPath('data.assignment.courier.id', $chosen->id)
        ->assertJsonPath('data.assignment.status', 'assigned');

    expect(Assignment::query()->sole()->courier_id)->toBe($chosen->id);
});

it('применяет правило подбора из запроса', function (): void {
    assignUseDistanceProvider();
    assignAsDispatcher();
    $order = assignOrder();
    assignCourier();

    $this->postJson(assignUrl($order), ['strategy' => 'least_loaded'])
        ->assertOk()
        ->assertJsonPath('data.assignment.strategy', 'least_loaded');

    expect(Assignment::query()->sole()->strategy)->toBe(AssignmentStrategy::LeastLoaded);
});

it('возвращает 409, если кандидатов нет', function (): void {
    assignUseDistanceProvider();
    assignAsDispatcher();
    $order = assignOrder();

    $this->postJson(assignUrl($order))
        ->assertStatus(409)
        ->assertJsonPath('code', 'NO_COURIER_AVAILABLE');

    expect(Assignment::query()->count())->toBe(0);
    expect($order->refresh()->status)->toBe(OrderStatus::Created);
});

it('возвращает 409, если указанный курьер недоступен', function (): void {
    assignUseDistanceProvider();
    assignAsDispatcher();
    $order = assignOrder();
    $offShift = assignCourier(withShift: false);

    $this->postJson(assignUrl($order), ['courier_id' => $offShift->id])
        ->assertStatus(409)
        ->assertJsonPath('code', 'COURIER_NOT_AVAILABLE');

    expect(Assignment::query()->count())->toBe(0);
});

it('возвращает 409, если указанный курьер занят активным назначением', function (): void {
    assignUseDistanceProvider();
    assignAsDispatcher();
    $order = assignOrder();
    $busy = assignCourier();

    Assignment::factory()->active()->create([
        'courier_id' => $busy->id,
        'order_id' => assignOrder()->id,
    ]);

    $this->postJson(assignUrl($order), ['courier_id' => $busy->id])
        ->assertStatus(409)
        ->assertJsonPath('code', 'COURIER_NOT_AVAILABLE');
});

it('возвращает 422 при несовпадении транспорта', function (): void {
    assignUseDistanceProvider();
    assignAsDispatcher();
    $order = assignOrder();
    $driver = assignCourier(transport: TransportType::Car, kind: null);

    $this->postJson(assignUrl($order), ['courier_id' => $driver->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'COURIER_TRANSPORT_MISMATCH')
        ->assertJsonPath('details.0.field', 'courier_id');
});

it('возвращает 422 при несовпадении зоны смены', function (): void {
    assignUseDistanceProvider();
    assignAsDispatcher();
    $order = assignOrder();
    $otherZone = ServiceZone::factory()->create();
    $courier = assignCourier($otherZone);

    $this->postJson(assignUrl($order), ['courier_id' => $courier->id])
        ->assertStatus(422)
        ->assertJsonPath('code', 'COURIER_ZONE_MISMATCH')
        ->assertJsonPath('details.0.field', 'courier_id');
});

it('проверяет статус заказа до подбора кандидатов', function (): void {
    assignUseDistanceProvider();
    assignAsDispatcher();
    $order = assignOrder();
    $order->status = OrderStatus::Assigned;
    $order->save();

    $this->postJson(assignUrl($order))
        ->assertStatus(409)
        ->assertJsonPath('code', 'ORDER_STATUS_TRANSITION_NOT_ALLOWED')
        ->assertJsonPath('details.0.field', 'status');
});

it('не создаёт второе назначение при повторном запросе', function (): void {
    assignUseDistanceProvider();
    assignAsDispatcher();
    $order = assignOrder();
    assignCourier();

    $this->postJson(assignUrl($order))->assertOk();

    $this->postJson(assignUrl($order))
        ->assertStatus(409)
        ->assertJsonPath('code', 'ORDER_STATUS_TRANSITION_NOT_ALLOWED');

    expect(Assignment::query()->count())->toBe(1);
});

it('отклоняет назначение, если активная попытка появилась в обход статуса заказа', function (): void {
    assignUseDistanceProvider();
    assignAsDispatcher();
    $order = assignOrder();
    assignCourier();

    // Так выглядит гонка: вторая транзакция ещё видит статус «создан»,
    // но активное назначение заказа уже вставлено параллельным запросом.
    Assignment::factory()->active()->create([
        'order_id' => $order->id,
        'courier_id' => User::factory()->courier()->create()->id,
    ]);

    $this->postJson(assignUrl($order))
        ->assertStatus(409)
        ->assertJsonPath('code', 'ORDER_STATUS_TRANSITION_NOT_ALLOWED');

    expect(Assignment::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('отклоняет неизвестное правило подбора в запросе', function (): void {
    assignUseDistanceProvider();
    assignAsDispatcher();
    $order = assignOrder();
    assignCourier();

    $this->postJson(assignUrl($order), ['strategy' => 'fastest'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('details.0.field', 'strategy');
});

it('отклоняет несуществующего курьера в запросе', function (): void {
    assignUseDistanceProvider();
    assignAsDispatcher();
    $order = assignOrder();

    $this->postJson(assignUrl($order), ['courier_id' => 999999])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('details.0.field', 'courier_id');
});

it('пускает к назначению только диспетчера', function (string $role): void {
    $order = assignOrder();

    $user = match ($role) {
        'sender' => User::factory()->sender()->create(),
        'courier' => User::factory()->courier()->create(),
        'admin' => User::factory()->admin()->create(),
        default => User::factory()->dispatcher()->create(),
    };

    Sanctum::actingAs($user);

    $this->postJson(assignUrl($order))
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    expect(Assignment::query()->count())->toBe(0);
})->with(['sender', 'courier', 'admin']);

it('отвечает 404 на несуществующий заказ', function (): void {
    assignAsDispatcher();

    $this->postJson('/api/v1/orders/999999/assign')
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');
});

it('не порождает лишних запросов к базе при назначении', function (): void {
    assignUseDistanceProvider();
    assignAsDispatcher();
    $order = assignOrder();
    assignCourier();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $this->postJson(assignUrl($order))->assertOk();

    expect(count(DB::getQueryLog()))->toBeLessThan(40);
});
