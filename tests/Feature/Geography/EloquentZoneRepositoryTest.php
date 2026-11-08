<?php

declare(strict_types=1);

use App\Domain\Geography\Coordinates;
use App\Domain\Geography\Exceptions\AddressOutsideServiceArea;
use App\Domain\Geography\ServiceArea;
use App\Domain\Geography\Zone;
use App\Domain\Geography\ZoneResolver;
use App\Models\Geography\ServiceZone;
use App\Services\Geography\EloquentZoneRepository;

it('возвращает активные зоны доменными объектами', function (): void {
    ServiceZone::factory()->create([
        'code' => 'msk-center',
        'name' => 'Центр Москвы',
        'base_fee_amount' => 19900,
    ]);

    $zones = (new EloquentZoneRepository)->active();

    expect($zones)->toHaveCount(1)
        ->and($zones[0]->code())->toBe('msk-center')
        ->and($zones[0]->name())->toBe('Центр Москвы')
        ->and($zones[0]->baseFee()->amount())->toBe(19900)
        ->and($zones[0]->baseFee()->currency())->toBe('RUB')
        ->and($zones[0]->isActive())->toBeTrue()
        ->and($zones[0]->serviceArea()->type())->toBe(ServiceArea::TYPE_RECTANGLE);
});

it('не возвращает деактивированные зоны', function (): void {
    ServiceZone::factory()->create(['code' => 'active-zone']);
    ServiceZone::factory()->inactive()->create(['code' => 'inactive-zone']);

    $codes = array_map(
        static fn (Zone $zone): string => $zone->code(),
        (new EloquentZoneRepository)->active(),
    );

    expect($codes)->toBe(['active-zone']);
});

it('превращает многоугольную границу в доменную зону', function (): void {
    ServiceZone::factory()->withBoundary([
        'type' => 'polygon',
        'points' => [
            ['latitude' => 55.70, 'longitude' => 37.50],
            ['latitude' => 55.70, 'longitude' => 37.60],
            ['latitude' => 55.80, 'longitude' => 37.60],
            ['latitude' => 55.80, 'longitude' => 37.50],
        ],
    ])->create(['code' => 'polygon-zone']);

    $zone = (new EloquentZoneRepository)->active()[0];

    expect($zone->serviceArea()->type())->toBe(ServiceArea::TYPE_POLYGON)
        ->and($zone->contains(new Coordinates(55.75, 37.55)))->toBeTrue()
        ->and($zone->contains(new Coordinates(55.75, 37.45)))->toBeFalse();
});

it('находит зону по координатам через резолвер из контейнера', function (): void {
    ServiceZone::factory()->create(['code' => 'msk-center']);

    $zone = app(ZoneResolver::class)->resolve(new Coordinates(55.75, 37.55));

    expect($zone->code())->toBe('msk-center');
});

it('отклоняет адрес вне зон через резолвер из контейнера', function (): void {
    ServiceZone::factory()->create(['code' => 'msk-center']);

    expect(static fn () => app(ZoneResolver::class)->resolve(new Coordinates(59.93, 30.31)))
        ->toThrow(AddressOutsideServiceArea::class);
});
