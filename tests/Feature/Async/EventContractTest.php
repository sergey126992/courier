<?php

declare(strict_types=1);

use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Geography\Contracts\DistanceProvider;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Order\OrderStatus;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OutboxMessage;
use App\Models\Tracking\CourierLastPosition;
use App\Models\User;
use App\Services\Events\JsonSchemaValidator;
use App\Services\Events\OutboxEventEnvelopeFactory;
use App\Services\Events\OutboxPublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Outbox;

/**
 * Назначение курьера в согласованной паре статусов заказа и назначения.
 */
function contractPendingAssignment(User $courier): Assignment
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

/**
 * Конверт обратного события оповещений (публикует сервис).
 *
 * @param array<string, mixed> $extra
 * @return array<string, mixed>
 */
function contractNotificationEnvelope(string $eventType, string $status, array $extra = []): array
{
    return [
        'message_id' => '1b0b4e5c-3a7e-4f1d-9f52-2f3f0a9d1c11',
        'event_type' => $eventType,
        'schema_version' => 'v1',
        'correlation_id' => 'req-0123456789abcdef',
        'occurred_at' => '2026-05-01T12:00:00+00:00',
        'aggregate_type' => 'notification',
        'aggregate_id' => 10,
        'payload' => array_merge([
            'event_id' => '1b0b4e5c-3a7e-4f1d-9f52-2f3f0a9d1c11',
            'notification_id' => 10,
            'order_id' => 1,
            'recipient_id' => 1,
            'channel' => 'email',
            'status' => $status,
            'attempt' => 1,
            'occurred_at' => '2026-05-01T12:00:00+00:00',
            'correlation_id' => 'req-0123456789abcdef',
        ], $extra),
    ];
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('проверяет публикуемые события заказа по общим схемам', function (): void {
    // Адреса заказа требуют зону обслуживания по адресу забора.
    estimateServiceZone();

    $courier = User::factory()->courier(TransportType::Light, LightVehicleKind::Bicycle)->create();

    CourierLastPosition::factory()->for($courier, 'courier')->create([
        'latitude' => 55.755826,
        'longitude' => 37.6173,
    ]);

    Sanctum::actingAs($courier);

    $this->postJson('/api/v1/courier/shifts')->assertCreated();

    $sender = User::factory()->sender()->create();
    Sanctum::actingAs($sender);

    $this->postJson('/api/v1/orders', orderPayload(), idempotencyHeader())->assertCreated();

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

    Sanctum::actingAs($courier);

    $this->postJson('/api/v1/assignments/'.$assignmentId.'/accept')->assertOk();
    $this->postJson('/api/v1/assignments/'.$assignmentId.'/pickup')->assertOk();
    $this->postJson('/api/v1/assignments/'.$assignmentId.'/deliver')->assertOk();

    $failed = contractPendingAssignment($courier);

    $this->postJson('/api/v1/assignments/'.$failed->id.'/fail', ['reason' => 'recipient_unavailable'])
        ->assertOk();

    $contracts = [
        'order.assigned',
        'order.created',
        'order.delivered',
        'order.failed',
        'order.picked_up',
    ];

    $validator = app(JsonSchemaValidator::class);
    $factory = app(OutboxEventEnvelopeFactory::class);
    $validated = [];

    foreach (OutboxMessage::query()->whereIn('event_type', $contracts)->orderBy('id')->get() as $event) {
        $envelope = $factory->fromMessage($event);

        $validator->validateEvent(
            $envelope->eventType->value,
            $envelope->schemaVersion->value,
            $envelope->toArray(),
        );

        $validated[] = $event->event_type;

        // В событиях заказа персональных данных нет (FR-NTF-235).
        foreach (['email', 'phone', 'name', 'address', 'comment'] as $forbidden) {
            expect($event->payload)->not->toHaveKey($forbidden);
        }
    }

    sort($validated);

    expect($validated)->toBe([...$contracts]);
});

it('проверяет обратные события и не находит в них персональных данных', function (): void {
    $validator = app(JsonSchemaValidator::class);

    $envelopes = [
        contractNotificationEnvelope('notification.sent', 'sent'),
        contractNotificationEnvelope('notification.failed', 'failed', ['error_code' => 'NOTIFICATION_SEND_FAILED']),
    ];

    foreach ($envelopes as $envelope) {
        expect(fn () => $validator->validateEvent($envelope['event_type'], 'v1', $envelope))
            ->not->toThrow(Throwable::class);

        foreach (['email', 'phone', 'name', 'address'] as $forbidden) {
            expect($envelope['payload'])->not->toHaveKey($forbidden);
        }
    }
});

it('оставляет событие в Outbox, если конверт не прошёл схему', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    // Все обязательные поля реестра на месте, но event_id не UUID: реестр
    // такую строку пропускает, а схема контракта — нет (FR-NTF-225).
    $message = Outbox::create([
        'payload' => Outbox::payload([
            'event_id' => 'not-a-uuid',
            'pickup_district' => 'Северный',
            'transport' => 'light',
            'price_amount' => 19900,
            'price_currency' => 'RUB',
        ]),
    ]);

    $result = app(OutboxPublisher::class)->run();

    $message->refresh();

    expect($result->published)->toBe(0)
        ->and($result->failed)->toBe(1)
        ->and($message->published_at)->toBeNull()
        ->and($message->last_error)->toBe('EVENT_CONTRACT_INVALID')
        ->and($message->attempts)->toBe((int) config('events.publish_max_attempts'));
});

it('не публикует событие без схемы нужной версии и не теряет строку', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    // Каталог с единственной схемой другой версии: контракт типа известен,
    // а нужной версии нет — событие остаётся в Outbox с кодом причины.
    $directory = storage_path('framework/testing/contracts-unknown-version');
    File::deleteDirectory($directory);
    File::ensureDirectoryExists($directory);
    File::copy(
        base_path('contracts/notifications/order-created.v1.json'),
        $directory.DIRECTORY_SEPARATOR.'order-created.v2.json',
    );

    config(['events.schema_path' => $directory]);
    app()->forgetInstance(JsonSchemaValidator::class);

    try {
        $message = Outbox::create();

        $result = app(OutboxPublisher::class)->run();

        $message->refresh();

        expect($result->published)->toBe(0)
            ->and($message->published_at)->toBeNull()
            ->and($message->last_error)->toBe('EVENT_CONTRACT_UNKNOWN');

        // Строка остаётся видимой для разбора, а не исчезает молча.
        expect(OutboxMessage::query()->whereKey($message->id)->exists())->toBeTrue();
    } finally {
        File::deleteDirectory($directory);
    }
});
