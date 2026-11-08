<?php

declare(strict_types=1);

use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Dispatch\FailureReason;
use App\Domain\Geography\Contracts\DistanceProvider;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Order\OrderStatus;
use App\Models\Dispatch\Assignment;
use App\Models\Dispatch\CourierShift;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OrderStatusHistory;
use App\Models\Tracking\CourierLastPosition;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Диспетчер с действующим токеном.
 */
function reassignAsDispatcher(): User
{
    $dispatcher = User::factory()->dispatcher()->create();
    Sanctum::actingAs($dispatcher);

    return $dispatcher;
}

/**
 * Предсказуемый провайдер расстояний.
 */
function reassignUseDistanceProvider(): void
{
    app()->instance(DistanceProvider::class, new FakeDistanceProvider(12.5));
}

/**
 * Заказ в заданном статусе для проверки переназначения.
 *
 * @param array<string, mixed> $attributes
 */
function reassignOrder(OrderStatus $status = OrderStatus::Failed, array $attributes = []): DeliveryOrder
{
    return DeliveryOrder::factory()->withStatus($status)->create(array_merge([
        'transport_type' => TransportType::Light,
        'light_vehicle_kind' => LightVehicleKind::Bicycle,
        'parcel' => [
            'weight_grams' => 1500,
            'length_cm' => 30,
            'width_cm' => 20,
            'height_cm' => 10,
        ],
        'distance_km' => 1.8,
    ], $attributes));
}

/**
 * Доступный курьер с открытой сменой и известной позицией.
 */
function reassignCourier(): User
{
    $courier = User::factory()->courier(TransportType::Light, LightVehicleKind::Bicycle)->create();

    CourierShift::factory()->open()->for($courier, 'courier')->create();

    CourierLastPosition::factory()->for($courier, 'courier')->create([
        'latitude' => 55.755826,
        'longitude' => 37.6173,
    ]);

    return $courier;
}

/**
 * Адрес переназначения заказа.
 */
function reassignUrl(DeliveryOrder $order): string
{
    return '/api/v1/orders/'.$order->id.'/reassign';
}

it('создаёт новую попытку после неудачи и сохраняет прежнюю', function (): void {
    reassignUseDistanceProvider();
    $dispatcher = reassignAsDispatcher();
    $courier = reassignCourier();
    $order = reassignOrder();

    $previous = Assignment::factory()
        ->failed(FailureReason::RecipientUnavailable, 'Не открыл дверь')
        ->create(['order_id' => $order->id, 'courier_id' => $courier->id]);

    $this->postJson(reassignUrl($order))
        ->assertOk()
        ->assertJsonPath('data.assignment.status', 'assigned')
        ->assertJsonPath('data.order.number', $order->number)
        ->assertJsonPath('data.order.status', 'assigned');

    expect($order->refresh()->status)->toBe(OrderStatus::Assigned);
    expect(Assignment::query()->where('order_id', $order->id)->count())->toBe(2);

    $previous->refresh();

    expect($previous->status)->toBe(AssignmentStatus::Failed)
        ->and($previous->failure_reason)->toBe(FailureReason::RecipientUnavailable)
        ->and($previous->failure_comment)->toBe('Не открыл дверь');

    $current = Assignment::query()
        ->where('order_id', $order->id)
        ->whereKeyNot($previous->id)
        ->sole();

    expect($current->status)->toBe(AssignmentStatus::Assigned)
        ->and($current->courier_id)->toBe($courier->id);

    $history = OrderStatusHistory::query()
        ->where('order_id', $order->id)
        ->orderBy('id')
        ->get();

    expect($history)->toHaveCount(2);

    $first = $history[0];
    $second = $history[1];

    assert($first instanceof OrderStatusHistory);
    assert($second instanceof OrderStatusHistory);

    expect($first->from_status)->toBe(OrderStatus::Failed)
        ->and($first->to_status)->toBe(OrderStatus::Created)
        ->and($first->changed_by)->toBe($dispatcher->id)
        ->and($second->from_status)->toBe(OrderStatus::Created)
        ->and($second->to_status)->toBe(OrderStatus::Assigned)
        ->and($second->changed_by)->toBe($dispatcher->id);
});

it('назначает указанного курьера при переназначении', function (): void {
    reassignUseDistanceProvider();
    reassignAsDispatcher();
    $courier = reassignCourier();
    $order = reassignOrder();

    Assignment::factory()
        ->failed()
        ->create(['order_id' => $order->id, 'courier_id' => User::factory()->courier()->create()->id]);

    $this->postJson(reassignUrl($order), ['courier_id' => $courier->id])
        ->assertOk()
        ->assertJsonPath('data.assignment.courier.id', $courier->id);
});

it('не переназначает заказ не в статусе «не выполнен»', function (): void {
    reassignUseDistanceProvider();
    reassignAsDispatcher();
    reassignCourier();
    $order = reassignOrder(OrderStatus::Created);

    $this->postJson(reassignUrl($order))
        ->assertStatus(409)
        ->assertJsonPath('code', 'ORDER_STATUS_TRANSITION_NOT_ALLOWED')
        ->assertJsonPath('details.0.field', 'status');

    expect(Assignment::query()->count())->toBe(0);
    expect($order->refresh()->status)->toBe(OrderStatus::Created);
});

it('пускает к переназначению только диспетчера', function (): void {
    $order = reassignOrder();

    Sanctum::actingAs(User::factory()->sender()->create());

    $this->postJson(reassignUrl($order))
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('отвечает 404 на несуществующий заказ', function (): void {
    reassignAsDispatcher();

    $this->postJson('/api/v1/orders/999999/reassign')
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');
});
