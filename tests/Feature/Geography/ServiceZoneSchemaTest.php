<?php

declare(strict_types=1);

use App\Models\Geography\ServiceZone;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

it('создаёт таблицу зон с ожидаемыми колонками', function (): void {
    expect(Schema::hasColumns('service_zones', [
        'id',
        'code',
        'name',
        'boundary',
        'base_fee_amount',
        'currency',
        'is_active',
        'created_at',
        'updated_at',
    ]))->toBeTrue();
});

it('сохраняет зону через фабрику', function (): void {
    $zone = ServiceZone::factory()->create([
        'code' => 'msk-center',
        'name' => 'Центр Москвы',
        'base_fee_amount' => 19900,
    ]);

    $this->assertDatabaseHas('service_zones', [
        'id' => $zone->id,
        'code' => 'msk-center',
        'name' => 'Центр Москвы',
        'base_fee_amount' => 19900,
        'currency' => 'RUB',
        'is_active' => true,
    ]);
});

it('приводит границу к массиву, ставку к целому и активность к булеву', function (): void {
    $zone = ServiceZone::factory()->create();
    $zone->refresh();

    // Порядок ключей в jsonb не сохраняется, поэтому сравниваем значения.
    expect($zone->boundary)->toEqual([
        'type' => 'rectangle',
        'south_west' => ['latitude' => 55.70, 'longitude' => 37.50],
        'north_east' => ['latitude' => 55.80, 'longitude' => 37.70],
    ])
        ->and($zone->base_fee_amount)->toBeInt()
        ->and($zone->is_active)->toBeTrue();
});

it('применяет значения по умолчанию для валюты и активности', function (): void {
    $zone = ServiceZone::create([
        'code' => 'msk-defaults',
        'name' => 'Зона с умолчаниями',
        'boundary' => [
            'type' => 'rectangle',
            'south_west' => ['latitude' => 55.70, 'longitude' => 37.50],
            'north_east' => ['latitude' => 55.80, 'longitude' => 37.60],
        ],
        'base_fee_amount' => 9900,
    ])->fresh();

    expect($zone?->currency)->toBe('RUB')
        ->and($zone?->is_active)->toBeTrue();
});

it('не допускает второй код, отличающийся только регистром', function (): void {
    ServiceZone::factory()->create(['code' => 'msk-center']);

    expect(static fn () => ServiceZone::factory()->create(['code' => 'MSK-Center']))
        ->toThrow(QueryException::class);
});

it('отдаёт скоупом только активные зоны', function (): void {
    ServiceZone::factory()->create(['code' => 'active-zone']);
    ServiceZone::factory()->inactive()->create(['code' => 'inactive-zone']);

    $codes = ServiceZone::query()->active()->pluck('code')->all();

    expect($codes)->toBe(['active-zone']);
});
