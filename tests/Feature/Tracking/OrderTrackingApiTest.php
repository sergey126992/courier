<?php

declare(strict_types=1);

use App\Domain\Geography\Contracts\DistanceProvider;
use App\Domain\Geography\Exceptions\DistanceProviderUnavailable;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Order\OrderStatus;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use App\Models\Tracking\AssignmentLastPosition;
use App\Models\Tracking\TrackingPoint;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * Заказ в работе с активным назначением, последней позицией и одной точкой ленты.
 *
 * Владелец заказа — отдельный отправитель; курьер — лёгкий транспорт «велосипед».
 *
 * @return array{0: DeliveryOrder, 1: Assignment, 2: User, 3: User}
 */
function trackingActiveOrder(): array
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

    return [$order, $assignment, $courier, $sender];
}

/**
 * Адрес просмотра трекинга заказа.
 */
function trackingUrl(DeliveryOrder $order): string
{
    return '/api/v1/orders/'.$order->id.'/tracking';
}

it('отдаёт владельцу статус, ленту, последнюю позицию и пересчитанный остаток', function (): void {
    Carbon::setTestNow('2026-05-12 12:00:00');
    [$order, , $courier, $sender] = trackingActiveOrder();

    Sanctum::actingAs($sender);
    app()->instance(DistanceProvider::class, new FakeDistanceProvider(4.5));

    $response = $this->getJson(trackingUrl($order))->assertOk();

    // «Велосипед»: 15 км/ч и 7 минут обработки; 4.5 км — это 18 минут движения.
    $response
        ->assertJsonPath('data.order.number', $order->number)
        ->assertJsonPath('data.order.status', 'picked_up')
        ->assertJsonPath('data.order.status_label', 'Забран')
        ->assertJsonPath('data.eta_minutes', 22)
        ->assertJsonPath('data.remaining_eta_minutes', 25)
        ->assertJsonPath('data.eta_source', 'computed')
        ->assertJsonPath('data.last_position.latitude', 55.76012)
        ->assertJsonPath('data.last_position.longitude', 37.6184)
        ->assertJsonPath('data.courier.name', $courier->name)
        ->assertJsonPath('data.courier.transport', 'light')
        ->assertJsonPath('data.courier.light_vehicle_kind', 'bicycle')
        ->assertJsonPath('data.points.0.source', 'courier')
        ->assertJsonPath('data.pagination.per_page', 50)
        ->assertJsonPath('data.pagination.has_more', false)
        ->assertJsonPath('data.pagination.next', null)
        ->assertJsonPath('data.pagination.prev', null);

    $data = (array) $response->json('data');
    $keys = array_keys($data);
    sort($keys);

    // Моменты времени база отдаёт в UTC: ожидание строится по прочитанному заказу.
    $etaAt = $order->refresh()->requested_at->copy()->addMinutes(22)->toIso8601String();

    // Состав ответа — ровно по ЧТЗ 8.5.
    expect($keys)->toBe([
        'courier',
        'eta_at',
        'eta_minutes',
        'eta_source',
        'last_position',
        'order',
        'pagination',
        'points',
        'remaining_eta_minutes',
    ])
        ->and($data['order'])->toBe([
            'number' => $order->number,
            'status' => 'picked_up',
            'status_label' => 'Забран',
        ])
        ->and($data['eta_at'])->toBe($etaAt)
        // Зафиксированный прогноз не переписывается (FR-TRK-125).
        ->and($order->eta_minutes)->toBe(22);
});

it('отдаёт трекинг диспетчеру в том же составе', function (): void {
    Carbon::setTestNow('2026-05-12 12:00:00');
    [$order] = trackingActiveOrder();

    Sanctum::actingAs(User::factory()->dispatcher()->create());
    app()->instance(DistanceProvider::class, new FakeDistanceProvider(4.5));

    $response = $this->getJson(trackingUrl($order))->assertOk()
        ->assertJsonPath('data.order.number', $order->number)
        ->assertJsonPath('data.remaining_eta_minutes', 25)
        ->assertJsonPath('data.eta_source', 'computed');

    $keys = array_keys((array) $response->json('data'));
    sort($keys);

    expect($keys)->toBe([
        'courier',
        'eta_at',
        'eta_minutes',
        'eta_source',
        'last_position',
        'order',
        'pagination',
        'points',
        'remaining_eta_minutes',
    ]);
});

it('не отличает чужой заказ от несуществующего', function (): void {
    [$order] = trackingActiveOrder();

    Sanctum::actingAs(User::factory()->sender()->create());

    $foreignResponse = $this->getJson(trackingUrl($order));
    $missingResponse = $this->getJson('/api/v1/orders/999999/tracking');

    $foreignResponse->assertStatus(404)->assertHeader('Content-Type', 'application/problem+json');
    $missingResponse->assertStatus(404);

    $foreignBody = (array) $foreignResponse->json();
    $missingBody = (array) $missingResponse->json();

    // Тела совпадают: отличается только адрес запроса в поле instance.
    unset($foreignBody['instance'], $missingBody['instance']);

    expect($foreignBody)->toBe($missingBody);
});

it('закрывает трекинг курьеру и администратору', function (): void {
    [$order] = trackingActiveOrder();

    foreach ([User::factory()->courier()->create(), User::factory()->admin()->create()] as $user) {
        Sanctum::actingAs($user);

        $this->getJson(trackingUrl($order))->assertStatus(403);
    }
});

it('отдаёт planned без остатка, когда последней позиции нет', function (): void {
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->withStatus(OrderStatus::Accepted)->create([
        'sender_id' => $sender->id,
        'eta_minutes' => 22,
    ]);
    Assignment::factory()->for($order, 'order')->accepted()->create();

    Sanctum::actingAs($sender);
    app()->instance(DistanceProvider::class, new FakeDistanceProvider(4.5));

    $response = $this->getJson(trackingUrl($order))->assertOk()
        ->assertJsonPath('data.eta_source', 'planned')
        ->assertJsonPath('data.last_position', null)
        ->assertJsonPath('data.points', []);

    expect((array) $response->json('data'))->not->toHaveKey('remaining_eta_minutes');
});

it('отдаёт planned по завершённому назначению, сохраняя ленту', function (): void {
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->withStatus(OrderStatus::Delivered)->create([
        'sender_id' => $sender->id,
        'eta_minutes' => 22,
    ]);
    $courier = User::factory()->courier(TransportType::Light, LightVehicleKind::Bicycle)->create();
    $assignment = Assignment::factory()
        ->for($order, 'order')
        ->for($courier, 'courier')
        ->delivered()
        ->create();

    AssignmentLastPosition::factory()->create(['assignment_id' => $assignment->id]);
    TrackingPoint::factory()->create(['assignment_id' => $assignment->id]);

    Sanctum::actingAs($sender);
    app()->instance(DistanceProvider::class, new FakeDistanceProvider(4.5));

    $response = $this->getJson(trackingUrl($order))->assertOk()
        ->assertJsonPath('data.eta_source', 'planned')
        ->assertJsonCount(1, 'data.points')
        ->assertJsonPath('data.courier.name', $courier->name);

    expect((array) $response->json('data'))->not->toHaveKey('remaining_eta_minutes');
});

it('не ломает ответ при недоступности провайдера расстояний', function (): void {
    Carbon::setTestNow('2026-05-12 12:00:00');
    [$order, , , $sender] = trackingActiveOrder();

    Sanctum::actingAs($sender);
    app()->instance(DistanceProvider::class, new FakeDistanceProvider(
        4.5,
        DistanceProviderUnavailable::forProvider('fake', 'timeout'),
    ));

    $response = $this->getJson(trackingUrl($order))->assertOk()
        ->assertJsonPath('data.eta_source', 'planned')
        ->assertJsonPath('data.eta_minutes', 22);

    expect((array) $response->json('data'))->not->toHaveKey('remaining_eta_minutes');
});

it('округляет остаток вверх и учитывает час пик', function (): void {
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->withStatus(OrderStatus::PickedUp)->create([
        'sender_id' => $sender->id,
        'transport_type' => TransportType::Car,
        'light_vehicle_kind' => null,
        'eta_minutes' => 22,
    ]);
    $courier = User::factory()->courier(TransportType::Car)->create();
    $assignment = Assignment::factory()
        ->for($order, 'order')
        ->for($courier, 'courier')
        ->pickedUp()
        ->create();

    AssignmentLastPosition::factory()->create(['assignment_id' => $assignment->id]);

    Sanctum::actingAs($sender);
    app()->instance(DistanceProvider::class, new FakeDistanceProvider(4.5));

    // Автомобиль: 25 км/ч, в час пик 18 км/ч, время обработки 5 минут.
    Carbon::setTestNow('2026-05-12 12:00:00');
    $this->getJson(trackingUrl($order))->assertOk()
        ->assertJsonPath('data.remaining_eta_minutes', 16);

    Carbon::setTestNow('2026-05-12 08:30:00');
    $this->getJson(trackingUrl($order))->assertOk()
        ->assertJsonPath('data.remaining_eta_minutes', 20);
});

it('пагинирует ленту курсором без дублей и пропусков', function (): void {
    $sender = User::factory()->sender()->create();
    $order = DeliveryOrder::factory()->withStatus(OrderStatus::Accepted)->create([
        'sender_id' => $sender->id,
    ]);
    $assignment = Assignment::factory()->for($order, 'order')->accepted()->create();

    $expected = [];

    for ($index = 0; $index < 5; $index++) {
        $recordedAt = now()->subMinutes(40 - $index * 10);

        TrackingPoint::factory()
            ->recordedAt($recordedAt)
            ->at(55.760120 + $index / 1000, 37.618400)
            ->create(['assignment_id' => $assignment->id]);

        // Моменты времени база хранит и отдаёт в UTC.
        $expected[] = $recordedAt->copy()->utc()->toIso8601String().'|'.(55.76012 + $index / 1000);
    }

    Sanctum::actingAs($sender);

    $collected = [];
    $url = trackingUrl($order).'?limit=2';

    for ($page = 0; $page < 5; $page++) {
        $response = $this->getJson($url)->assertOk();
        $rows = (array) $response->json('data.points');

        foreach ($rows as $row) {
            $row = (array) $row;
            $collected[] = $row['recorded_at'].'|'.$row['latitude'];
        }

        if (! (bool) $response->json('data.pagination.has_more')) {
            break;
        }

        $url = (string) $response->json('data.pagination.next');
    }

    expect($collected)->toBe($expected);
});

it('не порождает ленивых запросов при чтении трекинга', function (): void {
    Carbon::setTestNow('2026-05-12 12:00:00');
    [$order, , , $sender] = trackingActiveOrder();

    Sanctum::actingAs($sender);
    app()->instance(DistanceProvider::class, new FakeDistanceProvider(4.5));

    Model::preventLazyLoading();

    try {
        $this->getJson(trackingUrl($order))->assertOk()
            ->assertJsonPath('data.courier.light_vehicle_kind', 'bicycle')
            ->assertJsonPath('data.remaining_eta_minutes', 25);
    } finally {
        Model::preventLazyLoading(false);
    }
});
