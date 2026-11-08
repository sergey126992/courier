<?php

declare(strict_types=1);

use App\Domain\Geography\Contracts\DistanceProvider;
use App\Domain\Geography\Coordinates;
use App\Domain\Geography\Exceptions\DistanceProviderUnavailable;
use App\Models\Geography\ServiceZone;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Координаты адреса из тела запроса.
 *
 * @param array<string, mixed> $address
 */
function estimateCoordinates(array $address): Coordinates
{
    return new Coordinates((float) $address['latitude'], (float) $address['longitude']);
}

/**
 * Отправитель с подтверждённой почтой и активной зоной вокруг маршрутов тестов.
 */
function estimateSenderWithZone(): User
{
    estimateServiceZone();

    $user = User::factory()->sender()->create();
    Sanctum::actingAs($user);

    return $user;
}

it('считает расстояние по адресам, а не берёт готовое число из запроса', function (): void {
    estimateSenderWithZone();
    [$pickup, $dropoff] = estimateRoute(4.2);

    $response = $this->postJson('/api/v1/estimates', estimatePayload([
        'transport' => 'light',
        'light_vehicle_kind' => 'bicycle',
        'pickup' => $pickup,
        'dropoff' => $dropoff,
    ]))->assertOk();

    $expected = app(DistanceProvider::class)->between(
        estimateCoordinates($pickup),
        estimateCoordinates($dropoff),
    );

    $base = (int) config('pricing.transport.light.base_rate_minor');
    $perKm = (int) config('pricing.transport.light.rate_per_km_minor');
    $distance = (float) $response->json('distance_km');

    expect($distance)->toBe($expected->kilometers())
        ->and($distance)->toBe(4.2)
        // Цена и прогноз построены на расстоянии, посчитанном провайдером.
        ->and((int) $response->json('price.amount'))->toBe($base + (int) round($perKm * $distance))
        ->and((int) $response->json('eta_minutes'))->toBeGreaterThan(0);
});

it('отклоняет адрес забора вне зон обслуживания', function (): void {
    estimateSenderWithZone();

    // Санкт-Петербург: ни в одну тестовую зону не попадает.
    $response = $this->postJson('/api/v1/estimates', estimatePayload([
        'pickup' => estimateAddress(59.938784, 30.314997),
    ]));

    $response->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'ADDRESS_OUTSIDE_SERVICE_AREA')
        ->assertJsonPath('details.0.field', 'pickup');

    expect((string) $response->json('details.0.message'))->toContain('забора');
});

it('не мешает расчёту, если адрес доставки вне зон', function (): void {
    // Узкая зона: маршрут в 1.8 км на север выводит доставку за её границу.
    ServiceZone::factory()->create([
        'code' => 'msk-narrow',
        'boundary' => rectangleBoundary(55.70, 37.50, 55.76, 37.70),
    ]);

    Sanctum::actingAs(User::factory()->sender()->create());

    // Зона нужна только для забора (FR-GEO-115): доставка за её пределами допустима.
    $response = $this->postJson('/api/v1/estimates', estimatePayload());

    $response->assertOk();

    expect((float) $response->json('distance_km'))->toBe(1.8);
});

it('берёт коэффициент удлинения маршрута из конфигурации', function (): void {
    // Хелперы тестовых данных используют значение по умолчанию; тест ловит расхождение.
    expect((float) config('geography.distance.route_elongation_factor'))->toBe(ESTIMATE_ROUTE_ELONGATION);
});

it('возвращает 503 без внутренних подробностей, когда провайдер недоступен', function (): void {
    estimateSenderWithZone();

    app()->instance(
        DistanceProvider::class,
        new FakeDistanceProvider(failure: DistanceProviderUnavailable::forProvider('внешний', 'таймаут 3 с')),
    );

    $response = $this->postJson('/api/v1/estimates', estimatePayload());

    $response->assertStatus(503)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'DISTANCE_PROVIDER_UNAVAILABLE')
        ->assertJsonPath('details', []);

    // Причина сбоя и адреса клиенту не раскрываются.
    expect((string) $response->json('detail'))->not->toContain('таймаут')
        ->and((string) $response->json('detail'))->not->toContain('внешний');
});

it('учитывает зону, созданную через API', function (): void {
    geographyAsAdmin();

    $this->postJson('/api/v1/zones', zonePayload([
        'code' => 'api-zone',
        'boundary' => rectangleBoundary(55.70, 37.50, 55.80, 37.70),
    ]))->assertCreated();

    Sanctum::actingAs(User::factory()->sender()->create());

    $this->postJson('/api/v1/estimates', estimatePayload([
        'pickup' => estimateAddress(55.755826, 37.6173),
    ]))->assertOk();
});

it('перестаёт учитывать зону после деактивации через API', function (): void {
    geographyAsAdmin();

    $zoneId = (int) $this->postJson('/api/v1/zones', zonePayload(['code' => 'api-zone']))
        ->assertCreated()
        ->json('data.id');

    $this->patchJson('/api/v1/zones/'.$zoneId, ['is_active' => false])->assertOk();

    Sanctum::actingAs(User::factory()->sender()->create());

    $this->postJson('/api/v1/estimates', estimatePayload())
        ->assertStatus(422)
        ->assertJsonPath('code', 'ADDRESS_OUTSIDE_SERVICE_AREA');
});
