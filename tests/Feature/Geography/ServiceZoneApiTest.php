<?php

declare(strict_types=1);

use App\Domain\Identity\Role;
use App\Models\Geography\ServiceZone;
use App\Models\User;
use App\Rules\ServiceAreaBoundary;
use Laravel\Sanctum\Sanctum;

/**
 * Коды зон из ответа списка.
 *
 * @param array<int, array<string, mixed>> $zones
 * @return list<string>
 */
function zoneCodes(array $zones): array
{
    return array_values(array_map(
        static fn (array $zone): string => (string) $zone['code'],
        $zones,
    ));
}

/**
 * Пользователь неруководящей роли с токеном.
 */
function zoneAsRole(Role $role): User
{
    $user = match ($role) {
        Role::Courier => User::factory()->courier()->create(),
        Role::Dispatcher => User::factory()->dispatcher()->create(),
        default => User::factory()->sender()->create(),
    };

    Sanctum::actingAs($user);

    return $user;
}

it('создаёт зону администратором и отдаёт Location', function (): void {
    geographyAsAdmin();

    $response = $this->postJson('/api/v1/zones', zonePayload([
        'code' => 'msk-center',
        'base_fee_amount' => 24900,
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.code', 'msk-center')
        ->assertJsonPath('data.name', 'Центр Москвы')
        ->assertJsonPath('data.base_fee_amount', 24900)
        ->assertJsonPath('data.currency', 'RUB')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.boundary.type', 'rectangle')
        ->assertJsonPath('data.boundary.south_west.latitude', 55.6)
        ->assertJsonPath('data.boundary.north_east.longitude', 37.8);

    expect((string) $response->headers->get('Location'))
        ->toEndWith('/api/v1/zones/'.$response->json('data.id'));

    $this->assertDatabaseHas('service_zones', [
        'code' => 'msk-center',
        'name' => 'Центр Москвы',
        'base_fee_amount' => 24900,
        'is_active' => true,
    ]);
});

it('приводит код зоны к нижнему регистру при создании', function (): void {
    geographyAsAdmin();

    $this->postJson('/api/v1/zones', zonePayload(['code' => 'MSK-Center']))
        ->assertCreated()
        ->assertJsonPath('data.code', 'msk-center');

    $this->assertDatabaseHas('service_zones', ['code' => 'msk-center']);
});

it('отдаёт список зон с фильтром по активности', function (): void {
    geographyAsAdmin();

    ServiceZone::factory()->create(['code' => 'active-zone']);
    ServiceZone::factory()->inactive()->create(['code' => 'inactive-zone']);

    $all = $this->getJson('/api/v1/zones')->assertOk();
    expect(zoneCodes((array) $all->json('data')))->toBe(['active-zone', 'inactive-zone']);

    $active = $this->getJson('/api/v1/zones?is_active=1')->assertOk();
    expect(zoneCodes((array) $active->json('data')))->toBe(['active-zone']);

    $inactive = $this->getJson('/api/v1/zones?is_active=0')->assertOk();
    expect(zoneCodes((array) $inactive->json('data')))->toBe(['inactive-zone']);
});

it('изменяет зону и деактивирует её вместо удаления', function (): void {
    geographyAsAdmin();

    $zone = ServiceZone::factory()->create(['code' => 'msk-center']);

    $response = $this->patchJson('/api/v1/zones/'.$zone->id, [
        'name' => 'Центр',
        'base_fee_amount' => 25900,
        'is_active' => false,
        'boundary' => rectangleBoundary(55.70, 37.50, 55.80, 37.60),
    ]);

    $response->assertOk()
        ->assertJsonPath('data.name', 'Центр')
        ->assertJsonPath('data.base_fee_amount', 25900)
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.boundary.north_east.longitude', 37.6);

    $this->assertDatabaseHas('service_zones', [
        'id' => $zone->id,
        'name' => 'Центр',
        'base_fee_amount' => 25900,
        'is_active' => false,
    ]);
});

it('принимает границу прямоугольником', function (): void {
    geographyAsAdmin();

    $response = $this->postJson('/api/v1/zones', zonePayload([
        'code' => 'rectangle-zone',
        'boundary' => [
            'type' => 'rectangle',
            'south_west' => ['latitude' => 55.70, 'longitude' => 37.50],
            'north_east' => ['latitude' => 55.80, 'longitude' => 37.70],
        ],
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.boundary.type', 'rectangle')
        ->assertJsonPath('data.boundary.south_west.latitude', 55.7)
        ->assertJsonPath('data.boundary.south_west.longitude', 37.5)
        ->assertJsonPath('data.boundary.north_east.latitude', 55.8)
        ->assertJsonPath('data.boundary.north_east.longitude', 37.7);
});

it('принимает границу многоугольником', function (): void {
    geographyAsAdmin();

    $response = $this->postJson('/api/v1/zones', zonePayload([
        'code' => 'polygon-zone',
        'boundary' => [
            'type' => 'polygon',
            'points' => [
                ['latitude' => 55.70, 'longitude' => 37.50],
                ['latitude' => 55.70, 'longitude' => 37.70],
                ['latitude' => 55.80, 'longitude' => 37.70],
                ['latitude' => 55.80, 'longitude' => 37.50],
            ],
        ],
    ]));

    $response->assertCreated()
        ->assertJsonPath('data.boundary.type', 'polygon')
        ->assertJsonPath('data.boundary.points.0.latitude', 55.7)
        ->assertJsonPath('data.boundary.points.0.longitude', 37.5)
        ->assertJsonCount(4, 'data.boundary.points');
});

it('отклоняет старую форму координат одним сообщением', function (): void {
    geographyAsAdmin();

    $response = $this->postJson('/api/v1/zones', zonePayload([
        'boundary' => [
            'type' => 'rectangle',
            'south_west' => ['lat' => 55.70, 'lon' => 37.50],
            'north_east' => ['lat' => 55.80, 'lon' => 37.70],
        ],
    ]));

    $response->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('details.0.field', 'boundary')
        // Одна понятная ошибка вместо лавины вложенных сообщений по точкам.
        ->assertJsonCount(1, 'details');

    expect((string) $response->json('details.0.message'))
        ->toBe(ServiceAreaBoundary::FORMAT_MESSAGE)
        ->toContain('latitude')
        ->toContain('longitude');
});

it('не даёт изменить код зоны', function (): void {
    geographyAsAdmin();

    $zone = ServiceZone::factory()->create(['code' => 'msk-center']);

    $response = $this->patchJson('/api/v1/zones/'.$zone->id, ['code' => 'other-code']);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    $this->assertDatabaseHas('service_zones', ['id' => $zone->id, 'code' => 'msk-center']);
});

it('закрывает справочник зон неполномочным ролям', function (Role $role): void {
    zoneAsRole($role);
    $zone = ServiceZone::factory()->create(['code' => 'msk-center']);

    $this->getJson('/api/v1/zones')
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    $this->postJson('/api/v1/zones', zonePayload())
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    $this->patchJson('/api/v1/zones/'.$zone->id, ['name' => 'Другое'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
})->with([
    'отправитель' => [Role::Sender],
    'курьер' => [Role::Courier],
    'диспетчер' => [Role::Dispatcher],
]);

it('требует токен для справочника зон', function (): void {
    $this->getJson('/api/v1/zones')->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
    $this->postJson('/api/v1/zones', zonePayload())->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
    $this->patchJson('/api/v1/zones/1', ['name' => 'Другое'])->assertStatus(401)->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('отклоняет некорректную границу зоны', function (array $boundary): void {
    geographyAsAdmin();

    $response = $this->postJson('/api/v1/zones', zonePayload(['boundary' => $boundary]));

    // Одна ошибка по полю boundary: вложенных сообщений по точкам нет.
    $response->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonCount(1, 'details')
        ->assertJsonPath('details.0.field', 'boundary');

    expect((string) $response->json('details.0.message'))->toStartWith('Граница зоны:');
})->with([
    'обратный порядок углов' => [rectangleBoundary(55.80, 37.70, 55.70, 37.50)],
    'неизвестный тип' => [['type' => 'circle']],
    'меньше трёх точек' => [[
        'type' => 'polygon',
        'points' => [
            ['latitude' => 55.70, 'longitude' => 37.50],
            ['latitude' => 55.80, 'longitude' => 37.60],
        ],
    ]],
    'вырожденный многоугольник' => [[
        'type' => 'polygon',
        'points' => [
            ['latitude' => 55.70, 'longitude' => 37.50],
            ['latitude' => 55.75, 'longitude' => 37.55],
            ['latitude' => 55.80, 'longitude' => 37.60],
        ],
    ]],
    'координата вне диапазона' => [[
        'type' => 'rectangle',
        'south_west' => ['latitude' => 155.5, 'longitude' => 37.50],
        'north_east' => ['latitude' => 55.80, 'longitude' => 37.70],
    ]],
]);

it('отклоняет некорректный код зоны', function (string $code): void {
    geographyAsAdmin();

    $response = $this->postJson('/api/v1/zones', zonePayload(['code' => $code]));

    $response->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    $fields = array_map(
        static fn (array $item): string => (string) $item['field'],
        (array) $response->json('details'),
    );

    expect($fields)->toContain('code');
})->with([
    'подчёркивание' => ['msk_center'],
    'один символ' => ['m'],
    'длиннее шестнадцати' => ['zone-with-a-very-long-code'],
    'пробел' => ['msk center'],
]);

it('отклоняет дубликат кода в другом регистре', function (): void {
    geographyAsAdmin();

    ServiceZone::factory()->create(['code' => 'msk-center']);

    $response = $this->postJson('/api/v1/zones', zonePayload(['code' => 'MSK-CENTER']));

    $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');

    expect((string) $response->json('details.0.field'))->toBe('code');
});

it('отклоняет отрицательную базовую ставку', function (): void {
    geographyAsAdmin();

    $this->postJson('/api/v1/zones', zonePayload(['base_fee_amount' => -1]))
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');
});

it('отдаёт 404 на несуществующую зону', function (): void {
    geographyAsAdmin();

    $this->patchJson('/api/v1/zones/999999', ['name' => 'Другое'])
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');
});
