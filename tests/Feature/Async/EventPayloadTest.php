<?php

declare(strict_types=1);

use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Events\EventTypeRegistry;
use App\Domain\Geography\Contracts\DistanceProvider;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Order\OrderStatus;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OutboxMessage;
use App\Models\Tracking\CourierLastPosition;
use App\Models\User;
use App\Services\Events\OutboxEventEnvelopeFactory;
use Laravel\Sanctum\Sanctum;

/**
 * Назначение курьера в согласованной паре статусов заказа и назначения.
 */
function eventPayloadAssignment(User $courier): Assignment
{
    $order = DeliveryOrder::factory()->withStatus(OrderStatus::PickedUp)->create();

    return Assignment::factory()->create([
        'order_id' => $order->id,
        'courier_id' => $courier->id,
        'status' => AssignmentStatus::PickedUp,
        'accepted_at' => now(),
        'picked_up_at' => now(),
    ]);
}

it('пишет события только с разрешёнными ключами payload', function (): void {
    // Адреса заказа требуют зону обслуживания по адресу забора.
    estimateServiceZone();

    // Курьер открывает смену: courier.shift.started.
    $courier = User::factory()->courier(TransportType::Light, LightVehicleKind::Bicycle)->create();

    CourierLastPosition::factory()->for($courier, 'courier')->create([
        'latitude' => 55.755826,
        'longitude' => 37.6173,
    ]);

    Sanctum::actingAs($courier);

    $shiftId = $this->postJson('/api/v1/courier/shifts')->assertCreated()->json('data.id');

    // Отправитель создаёт заказ: order.created.
    $sender = User::factory()->sender()->create();
    Sanctum::actingAs($sender);

    $this->postJson('/api/v1/orders', orderPayload(), idempotencyHeader())->assertCreated();

    // Заказ, который диспетчер назначает курьеру: order.assigned.
    $deliveredOrder = DeliveryOrder::factory()->create([
        'sender_id' => $sender->id,
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

    app()->instance(DistanceProvider::class, new FakeDistanceProvider(1.2));

    Sanctum::actingAs(User::factory()->dispatcher()->create());

    $assignmentId = $this->postJson('/api/v1/orders/'.$deliveredOrder->id.'/assign')
        ->assertOk()
        ->json('data.assignment.id');

    // Курьер везёт заказ: order.picked_up и order.delivered.
    Sanctum::actingAs($courier);

    $this->postJson('/api/v1/assignments/'.$assignmentId.'/accept')->assertOk();
    $this->postJson('/api/v1/assignments/'.$assignmentId.'/pickup')->assertOk();
    $this->postJson('/api/v1/assignments/'.$assignmentId.'/deliver')->assertOk();

    // Вторая доставка срывается: order.failed.
    $failed = eventPayloadAssignment($courier);

    $this->postJson('/api/v1/assignments/'.$failed->id.'/fail', ['reason' => 'recipient_unavailable'])
        ->assertOk();

    // Отмена заказа отправителем: order.cancelled.
    $cancelledOrder = DeliveryOrder::factory()->withStatus(OrderStatus::Created)->create(['sender_id' => $sender->id]);
    Sanctum::actingAs($sender);

    $this->postJson('/api/v1/orders/'.$cancelledOrder->id.'/cancel')->assertOk();

    // Закрытие смены: courier.shift.ended.
    Sanctum::actingAs($courier);

    $this->postJson('/api/v1/courier/shifts/'.$shiftId.'/close')->assertOk();

    $events = OutboxMessage::query()->orderBy('id')->get();

    expect($events->pluck('event_type')->sort()->values()->all())->toBe([
        'courier.shift.ended',
        'courier.shift.started',
        'order.assigned',
        'order.cancelled',
        'order.created',
        'order.delivered',
        'order.failed',
        'order.picked_up',
    ]);

    // Лишние поля допустимы, но состав ограничен: ни адреса, ни координат,
    // ни телефона, ни email, ни свободного комментария в payload нет.
    $allowedExtra = [
        'courier.shift.ended' => ['zone_id', 'started_at'],
    ];

    $forbidden = [
        'email',
        'phone',
        'address',
        'pickup_address',
        'dropoff_address',
        'latitude',
        'longitude',
        'comment',
        'failure_comment',
        'name',
    ];

    $registry = new EventTypeRegistry;
    $factory = app(OutboxEventEnvelopeFactory::class);

    foreach ($events as $event) {
        $required = $registry->requiredPayloadFields($event->event_type);
        $allowed = array_merge($required, $allowedExtra[$event->event_type] ?? []);

        foreach (array_keys($event->payload) as $key) {
            expect($allowed)->toContain($key);
        }

        foreach ($required as $field) {
            expect(array_key_exists($field, $event->payload))->toBeTrue();
        }

        foreach ($forbidden as $key) {
            expect($event->payload)->not->toHaveKey($key);
        }

        $envelope = $factory->fromMessage($event);

        // Сквозной идентификатор запроса есть у каждого события (FR-OPS-201),
        // а event_id события заказа совпадает с message_id строки Outbox.
        expect($envelope->correlationId)->not->toBeNull()
            ->and($envelope->eventType->value)->toBe($event->event_type)
            ->and($envelope->schemaVersion->value)->toBe('v1')
            ->and($envelope->aggregateType->value)->toBe($event->aggregate_type);

        if (str_starts_with($event->event_type, 'order.')) {
            expect($event->payload['event_id'])->toBe($event->message_id)
                ->and($event->payload['status'])->toBeString()
                ->and($event->payload['occurred_at'])->toBeString()
                ->and($event->payload['locale'])->toBeString()
                ->and($event->payload['tracking_code'])->toBeString();
        }
    }
});
