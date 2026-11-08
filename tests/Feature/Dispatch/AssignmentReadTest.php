<?php

declare(strict_types=1);

use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Dispatch\AssignmentStrategy;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Order\OrderStatus;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\Sanctum;

/**
 * Курьер с действующим токеном.
 */
function assignmentReadCourier(): User
{
    $courier = User::factory()->courier(TransportType::Light, LightVehicleKind::Bicycle)->create();
    Sanctum::actingAs($courier);

    return $courier;
}

/**
 * Адрес карточки назначения.
 */
function assignmentReadUrl(Assignment $assignment): string
{
    return '/api/v1/assignments/'.$assignment->id;
}

it('отдаёт список только своих назначений: активное первым, затем история', function (): void {
    $courier = assignmentReadCourier();
    $other = User::factory()->courier()->create();

    $active = Assignment::factory()->create([
        'courier_id' => $courier->id,
        'status' => AssignmentStatus::Assigned,
        'assigned_at' => now()->subDay(),
    ]);
    $newer = Assignment::factory()->delivered()->create([
        'courier_id' => $courier->id,
        'assigned_at' => now(),
    ]);
    $older = Assignment::factory()->failed()->create([
        'courier_id' => $courier->id,
        'assigned_at' => now()->subHours(5),
    ]);
    Assignment::factory()->delivered()->create(['courier_id' => $other->id]);

    $response = $this->getJson('/api/v1/assignments')->assertOk();

    $ids = array_map(
        static fn (array $row): int => (int) $row['id'],
        (array) $response->json('data'),
    );

    expect($ids)->toBe([$active->id, $newer->id, $older->id])
        ->and($response->json('pagination.per_page'))->toBe(20)
        ->and($response->json('pagination.has_more'))->toBeFalse()
        ->and($response->json('pagination.next'))->toBeNull();

    $first = (array) $response->json('data.0');

    expect($first)->toHaveKeys(['id', 'status', 'status_label', 'order', 'pickup', 'dropoff', 'assigned_at'])
        ->and($first['status'])->toBe('assigned')
        ->and($first['status_label'])->toBe('Назначено')
        ->and($first['order'])->toHaveKeys(['number', 'status', 'status_label'])
        ->and($first['pickup'])->toHaveKey('district')
        ->and($first['dropoff'])->toHaveKey('district');
});

it('пагинирует список курсором без дублей и пропусков', function (): void {
    $courier = assignmentReadCourier();

    $active = Assignment::factory()->create([
        'courier_id' => $courier->id,
        'status' => AssignmentStatus::Assigned,
        'assigned_at' => now()->subDays(2),
    ]);

    for ($index = 0; $index < 12; $index++) {
        Assignment::factory()->delivered()->create([
            'courier_id' => $courier->id,
            'assigned_at' => now()->subMinutes(12 - $index),
        ]);
    }

    $first = $this->getJson('/api/v1/assignments?limit=5')->assertOk();

    $ids = array_map(
        static fn (array $row): int => (int) $row['id'],
        (array) $first->json('data'),
    );

    expect($ids)->toHaveCount(5)
        ->and($ids[0])->toBe($active->id)
        ->and($first->json('pagination.per_page'))->toBe(5)
        ->and($first->json('pagination.has_more'))->toBeTrue()
        ->and($first->json('pagination.next'))->not->toBeNull();

    $next = $first->json('pagination.next');

    while (is_string($next)) {
        $page = $this->getJson($next)->assertOk();

        foreach ((array) $page->json('data') as $row) {
            $ids[] = (int) $row['id'];
        }

        $next = $page->json('pagination.next');
    }

    expect($ids)->toHaveCount(13)
        ->and(array_unique($ids))->toHaveCount(13);
});

it('ограничивает размер страницы списка сотней', function (): void {
    assignmentReadCourier();

    $this->getJson('/api/v1/assignments?limit=500')
        ->assertOk()
        ->assertJsonPath('pagination.per_page', 100);
});

it('отдаёт карточку назначения по контракту ЧТЗ 8.3', function (): void {
    $courier = assignmentReadCourier();
    $order = DeliveryOrder::factory()->withStatus(OrderStatus::Accepted)->create();

    $assignment = Assignment::factory()->accepted()->create([
        'courier_id' => $courier->id,
        'order_id' => $order->id,
        'strategy' => AssignmentStrategy::Nearest,
        'distance_to_pickup_km' => 1.5,
    ]);

    $this->getJson(assignmentReadUrl($assignment))
        ->assertOk()
        ->assertJsonPath('data.id', $assignment->id)
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.status_label', 'Принято')
        ->assertJsonPath('data.strategy', 'nearest')
        ->assertJsonPath('data.distance_to_pickup_km', 1.5)
        ->assertJsonPath('data.assigned_at', static fn (mixed $value): bool => is_string($value))
        ->assertJsonPath('data.accepted_at', static fn (mixed $value): bool => is_string($value))
        ->assertJsonPath('data.picked_up_at', null)
        ->assertJsonPath('data.delivered_at', null)
        ->assertJsonPath('data.failed_at', null)
        ->assertJsonPath('data.failure_reason', null)
        ->assertJsonPath('data.failure_comment', null)
        ->assertJsonPath('data.courier.id', $courier->id)
        ->assertJsonPath('data.courier.transport', 'light')
        ->assertJsonPath('data.courier.light_vehicle_kind', 'bicycle')
        ->assertJsonPath('data.order.number', $order->number)
        ->assertJsonPath('data.order.status', 'accepted')
        ->assertJsonPath('data.order.status_label', 'Принят')
        ->assertJsonPath('data.order.pickup.district', 'Тверской')
        ->assertJsonPath('data.order.pickup.street', 'Тверская')
        ->assertJsonPath('data.order.pickup.house', '7')
        ->assertJsonPath('data.order.dropoff.house', '9')
        ->assertJsonPath('data.order.parcel.weight_grams', 1500)
        ->assertJsonPath('data.order.price.amount', 19400)
        ->assertJsonPath('data.order.price.currency', 'RUB')
        ->assertJsonPath('data.order.eta_minutes', 15)
        ->assertJsonPath('data.order.requested_at', static fn (mixed $value): bool => is_string($value));
});

it('скрывает чужое назначение за 404', function (): void {
    assignmentReadCourier();
    $foreign = Assignment::factory()->create(['courier_id' => User::factory()->courier()->create()->id]);

    $this->getJson(assignmentReadUrl($foreign))
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');
});

it('пускает диспетчера к карточке и списку не пускает', function (): void {
    $assignment = Assignment::factory()->create(['courier_id' => User::factory()->courier()->create()->id]);

    Sanctum::actingAs(User::factory()->dispatcher()->create());

    $this->getJson(assignmentReadUrl($assignment))
        ->assertOk()
        ->assertJsonPath('data.id', $assignment->id);

    $this->getJson('/api/v1/assignments')
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('не пускает отправителя и администратора к назначениям', function (string $role): void {
    $assignment = Assignment::factory()->create(['courier_id' => User::factory()->courier()->create()->id]);

    $user = $role === 'admin'
        ? User::factory()->admin()->create()
        : User::factory()->sender()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/assignments')->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    $this->getJson(assignmentReadUrl($assignment))->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
})->with([
    'отправитель' => ['sender'],
    'администратор' => ['admin'],
]);

it('читает список и карточку без ленивых загрузок', function (): void {
    $courier = assignmentReadCourier();

    $assignment = Assignment::factory()->accepted()->create([
        'courier_id' => $courier->id,
        'order_id' => DeliveryOrder::factory()->withStatus(OrderStatus::Accepted)->create()->id,
    ]);

    Model::preventLazyLoading();

    try {
        $this->getJson('/api/v1/assignments')->assertOk();
        $this->getJson(assignmentReadUrl($assignment))
            ->assertOk()
            ->assertJsonPath('data.order.status', 'accepted');
    } finally {
        Model::preventLazyLoading(false);
    }
});
