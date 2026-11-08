<?php

declare(strict_types=1);

use App\Domain\Geography\Contracts\DistanceProvider;
use App\Domain\Geography\Coordinates;
use App\Domain\Geography\Exceptions\DistanceProviderUnavailable;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Order\OrderStatus;
use App\Domain\Pricing\Distance as PricingDistance;
use App\Models\Dispatch\Assignment;
use App\Models\Dispatch\CourierShift;
use App\Models\Geography\ServiceZone;
use App\Models\Order\DeliveryOrder;
use App\Models\Tracking\CourierLastPosition;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;

/**
 * Провайдер расстояний с предсказуемым результатом: 1° широты — 111 км.
 *
 * Тесты подбора не зависят от коэффициента удлинения маршрута и таймингов
 * реального провайдера: расстояние задаётся координатой позиции курьера.
 */
final class CandidatesLatitudeDistanceProvider implements DistanceProvider
{
    public function between(Coordinates $from, Coordinates $to): PricingDistance
    {
        return PricingDistance::fromKilometers(round(abs($from->latitude() - $to->latitude()) * 111, 2));
    }
}

/**
 * Диспетчер с действующим токеном.
 */
function candidatesAsDispatcher(): User
{
    $dispatcher = User::factory()->dispatcher()->create();
    Sanctum::actingAs($dispatcher);

    return $dispatcher;
}

/**
 * Подменяет провайдер расстояний предсказуемым.
 */
function candidatesUseDistanceProvider(): void
{
    app()->instance(DistanceProvider::class, new CandidatesLatitudeDistanceProvider);
}

/**
 * Заказ в статусе «создан» с параметрами, проходящими по лёгкому транспорту.
 *
 * @param array<string, mixed> $attributes
 */
function candidatesOrder(array $attributes = []): DeliveryOrder
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
 * Курьер с профилем, сменой, позицией и назначениями за смену.
 *
 * @param bool $withShift открытая смена: без неё курьер недоступен
 * @param bool $active активность профиля курьера
 * @param int $assignmentsInShift завершённые назначения с начала смены
 */
function candidatesCourier(
    ?float $latitude = null,
    ?float $longitude = null,
    ?ServiceZone $zone = null,
    bool $active = true,
    bool $withShift = true,
    int $assignmentsInShift = 0,
    TransportType $transport = TransportType::Light,
    ?LightVehicleKind $kind = LightVehicleKind::Bicycle,
): User {
    $courier = User::factory()->courier($transport, $kind)->create();

    if (! $active) {
        $courier->courierProfile()->update(['is_active' => false]);
    }

    $startedAt = now()->subHour();

    if ($withShift) {
        CourierShift::factory()->open()->for($courier, 'courier')->create([
            'zone_id' => $zone?->id,
            'started_at' => $startedAt,
        ]);
    }

    for ($index = 0; $index < $assignmentsInShift; $index++) {
        Assignment::factory()->delivered()->create([
            'courier_id' => $courier->id,
            'order_id' => DeliveryOrder::factory()->create()->id,
            'assigned_at' => $startedAt->copy()->addMinutes(10),
        ]);
    }

    if ($latitude !== null && $longitude !== null) {
        CourierLastPosition::factory()->for($courier, 'courier')->create([
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }

    return $courier;
}

/**
 * Адрес кандидатов заказа.
 */
function candidatesUrl(DeliveryOrder $order): string
{
    return '/api/v1/orders/'.$order->id.'/candidates';
}

it('возвращает подходящих курьеров в порядке применённого правила', function (): void {
    candidatesUseDistanceProvider();
    candidatesAsDispatcher();

    $zone = ServiceZone::factory()->create();
    $order = candidatesOrder(['zone_id' => $zone->id]);

    // Позиция курьера совпадает с точкой забора, у второго — в 11.1 км.
    $near = candidatesCourier(55.755826, 37.6173, $zone, assignmentsInShift: 1);
    $far = candidatesCourier(55.855826, 37.6173, $zone);

    $this->getJson(candidatesUrl($order))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.strategy', 'nearest')
        ->assertJsonPath('meta.order_eta_minutes', 15)
        ->assertJsonPath('data.0.id', $near->id)
        ->assertJsonPath('data.1.id', $far->id)
        ->assertJsonPath('data.0.transport', 'light')
        ->assertJsonPath('data.0.light_vehicle_kind', 'bicycle')
        ->assertJsonPath('data.0.distance_to_pickup_km', 0)
        ->assertJsonPath('data.0.pickup_approach_minutes', 0)
        ->assertJsonPath('data.0.assignments_in_shift', 1)
        ->assertJsonPath('data.1.distance_to_pickup_km', 11.1)
        ->assertJsonPath('data.1.pickup_approach_minutes', 45);
});

it('исключает курьера с неактивным профилем', function (): void {
    candidatesUseDistanceProvider();
    candidatesAsDispatcher();

    $order = candidatesOrder();
    $active = candidatesCourier(55.755826, 37.6173);
    candidatesCourier(55.756826, 37.6173, active: false);

    $this->getJson(candidatesUrl($order))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $active->id);
});

it('исключает курьера без открытой смены', function (): void {
    candidatesUseDistanceProvider();
    candidatesAsDispatcher();

    $order = candidatesOrder();
    $available = candidatesCourier(55.755826, 37.6173);

    // Смена не открыта вовсе и смена уже закрыта — оба курьера недоступны.
    candidatesCourier(55.756826, 37.6173, withShift: false);
    $closed = candidatesCourier(55.757826, 37.6173, withShift: false);
    CourierShift::factory()->closed()->for($closed, 'courier')->create();

    $this->getJson(candidatesUrl($order))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $available->id);
});

it('исключает курьера с активным назначением', function (): void {
    candidatesUseDistanceProvider();
    candidatesAsDispatcher();

    $order = candidatesOrder();
    $available = candidatesCourier(55.755826, 37.6173);
    $busy = candidatesCourier(55.756826, 37.6173);

    Assignment::factory()->active()->create([
        'courier_id' => $busy->id,
        'order_id' => DeliveryOrder::factory()->create()->id,
    ]);

    $this->getJson(candidatesUrl($order))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $available->id);
});

it('исключает курьера с другим типом транспорта', function (): void {
    candidatesUseDistanceProvider();
    candidatesAsDispatcher();

    $order = candidatesOrder();
    $cyclist = candidatesCourier(55.755826, 37.6173);
    candidatesCourier(55.756826, 37.6173, transport: TransportType::Car, kind: null);

    $this->getJson(candidatesUrl($order))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $cyclist->id);
});

it('учитывает зону смены и пропускает смену без зоны', function (): void {
    candidatesUseDistanceProvider();
    candidatesAsDispatcher();

    $orderZone = ServiceZone::factory()->create();
    $otherZone = ServiceZone::factory()->create();
    $order = candidatesOrder(['zone_id' => $orderZone->id]);

    $inZone = candidatesCourier(55.755826, 37.6173, $orderZone);
    $anyZone = candidatesCourier(55.756826, 37.6173);
    candidatesCourier(55.757826, 37.6173, $otherZone);

    $this->getJson(candidatesUrl($order))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $inZone->id)
        ->assertJsonPath('data.1.id', $anyZone->id);
});

it('исключает всех курьеров, если посылка не проходит по весу или радиусу', function (): void {
    candidatesUseDistanceProvider();
    candidatesAsDispatcher();

    // Предел лёгкого транспорта — 15 000 г и 7 км (config/pricing.php).
    $heavy = candidatesOrder([
        'parcel' => ['weight_grams' => 20000, 'length_cm' => 30, 'width_cm' => 20, 'height_cm' => 10],
    ]);
    $far = candidatesOrder(['distance_km' => 10]);

    candidatesCourier(55.755826, 37.6173);
    candidatesCourier(55.756826, 37.6173);

    $this->getJson(candidatesUrl($heavy))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson(candidatesUrl($far))->assertOk()->assertJsonCount(0, 'data');
});

it('ставит кандидата без позиции последним, но не исключает его', function (): void {
    candidatesUseDistanceProvider();
    candidatesAsDispatcher();

    $order = candidatesOrder();
    $known = candidatesCourier(55.765826, 37.6173);
    $unknown = candidatesCourier();

    $this->getJson(candidatesUrl($order))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $known->id)
        ->assertJsonPath('data.1.id', $unknown->id)
        ->assertJsonPath('data.1.distance_to_pickup_km', null)
        ->assertJsonPath('data.1.pickup_approach_minutes', null);
});

it('не падает, если провайдер расстояний недоступен', function (): void {
    candidatesAsDispatcher();

    app()->instance(
        DistanceProvider::class,
        new FakeDistanceProvider(failure: DistanceProviderUnavailable::forProvider('внешний', 'таймаут 3 с')),
    );

    $order = candidatesOrder();
    $courier = candidatesCourier(55.755826, 37.6173);

    $this->getJson(candidatesUrl($order))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $courier->id)
        ->assertJsonPath('data.0.distance_to_pickup_km', null)
        ->assertJsonPath('data.0.pickup_approach_minutes', null);
});

it('возвращает одинаковый порядок при одинаковых данных', function (): void {
    candidatesUseDistanceProvider();
    candidatesAsDispatcher();

    $order = candidatesOrder();
    $first = candidatesCourier(55.755826, 37.6173);
    $second = candidatesCourier(55.755826, 37.6173);

    $this->getJson(candidatesUrl($order))
        ->assertOk()
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.1.id', $second->id);

    $this->getJson(candidatesUrl($order))
        ->assertOk()
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.1.id', $second->id);
});

it('ограничивает число кандидатов значением конфигурации', function (): void {
    candidatesUseDistanceProvider();
    candidatesAsDispatcher();

    config(['dispatch.candidates_limit' => 1]);

    $order = candidatesOrder();
    candidatesCourier(55.755826, 37.6173);
    candidatesCourier(55.756826, 37.6173);

    $this->getJson(candidatesUrl($order))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('меняет порядок по параметру strategy', function (): void {
    candidatesUseDistanceProvider();
    candidatesAsDispatcher();

    $order = candidatesOrder();
    $near = candidatesCourier(55.755826, 37.6173, assignmentsInShift: 2);
    $free = candidatesCourier(55.855826, 37.6173);

    $this->getJson(candidatesUrl($order).'?strategy=nearest')
        ->assertOk()
        ->assertJsonPath('meta.strategy', 'nearest')
        ->assertJsonPath('data.0.id', $near->id);

    $this->getJson(candidatesUrl($order).'?strategy=least_loaded')
        ->assertOk()
        ->assertJsonPath('meta.strategy', 'least_loaded')
        ->assertJsonPath('data.0.id', $free->id);
});

it('учитывает часы пик во времени подхода к забору', function (): void {
    candidatesUseDistanceProvider();
    candidatesAsDispatcher();

    $order = candidatesOrder([
        'transport_type' => TransportType::Car,
        'light_vehicle_kind' => null,
        'distance_km' => 10,
    ]);
    candidatesCourier(55.855826, 37.6173, transport: TransportType::Car, kind: null);

    // Час пик: автомобиль едет 18 км/ч — 11.1 км занимают 37 минут.
    $this->travelTo(Carbon::parse('2026-03-10 08:00:00', 'Europe/Moscow'));
    $this->getJson(candidatesUrl($order))
        ->assertOk()
        ->assertJsonPath('data.0.pickup_approach_minutes', 37);

    // Вне часов пик: 25 км/ч — тот же путь занимает 27 минут.
    $this->travelTo(Carbon::parse('2026-03-10 15:00:00', 'Europe/Moscow'));
    $this->getJson(candidatesUrl($order))
        ->assertOk()
        ->assertJsonPath('data.0.pickup_approach_minutes', 27);
});

it('отвечает 500, если в конфигурации неизвестное правило подбора', function (): void {
    candidatesUseDistanceProvider();
    candidatesAsDispatcher();

    // Доменная ошибка настройки пишется в журнал — в тесте он не нужен.
    Log::spy();
    config(['dispatch.strategy' => 'teleport']);
    $order = candidatesOrder();
    candidatesCourier(55.755826, 37.6173);

    $this->getJson(candidatesUrl($order))
        ->assertStatus(500)
        ->assertJsonPath('code', 'INTERNAL_ERROR');
});

it('отклоняет неизвестное правило подбора в запросе', function (): void {
    candidatesUseDistanceProvider();
    candidatesAsDispatcher();

    $order = candidatesOrder();
    candidatesCourier(55.755826, 37.6173);

    $this->getJson(candidatesUrl($order).'?strategy=fastest')
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('details.0.field', 'strategy');
});

it('пускает к кандидатам только диспетчера', function (string $role): void {
    $order = candidatesOrder();

    $user = match ($role) {
        'sender' => User::factory()->sender()->create(),
        'courier' => User::factory()->courier()->create(),
        'admin' => User::factory()->admin()->create(),
        default => User::factory()->dispatcher()->create(),
    };

    Sanctum::actingAs($user);

    $response = $this->getJson(candidatesUrl($order));

    if ($role === 'dispatcher') {
        $response->assertOk();
    } else {
        $response->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    }
})->with(['sender', 'courier', 'admin', 'dispatcher']);

it('запрещает список кандидатов для заказа не в статусе «создан»', function (): void {
    candidatesUseDistanceProvider();
    candidatesAsDispatcher();

    $order = candidatesOrder();
    $order->status = OrderStatus::Assigned;
    $order->save();

    candidatesCourier(55.755826, 37.6173);

    $this->getJson(candidatesUrl($order))
        ->assertStatus(409)
        ->assertJsonPath('code', 'ORDER_STATUS_TRANSITION_NOT_ALLOWED');
});

it('отвечает 404 на несуществующий заказ', function (): void {
    candidatesAsDispatcher();

    $this->getJson('/api/v1/orders/999999/candidates')
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');
});

it('не порождает дополнительных запросов при росте числа кандидатов', function (): void {
    candidatesUseDistanceProvider();
    candidatesAsDispatcher();

    $order = candidatesOrder();
    candidatesCourier(55.755826, 37.6173);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->getJson(candidatesUrl($order))->assertOk();
    $single = count(DB::getQueryLog());

    candidatesCourier(55.756826, 37.6173);
    candidatesCourier(55.757826, 37.6173);

    DB::flushQueryLog();
    $this->getJson(candidatesUrl($order))->assertOk()->assertJsonCount(3, 'data');
    $triple = count(DB::getQueryLog());

    expect($triple)->toBe($single);
});
