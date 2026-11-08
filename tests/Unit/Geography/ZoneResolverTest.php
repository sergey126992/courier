<?php

declare(strict_types=1);

use App\Domain\Geography\Contracts\ZoneRepository;
use App\Domain\Geography\Coordinates;
use App\Domain\Geography\Exceptions\AddressOutsideServiceArea;
use App\Domain\Geography\ServiceArea;
use App\Domain\Geography\Zone;
use App\Domain\Geography\ZoneResolver;
use App\Domain\Pricing\Money;

/**
 * Репозиторий-заглушка: отдаёт заданный набор зон в заданном порядке.
 *
 * @param list<Zone> $zones
 */
function fakeZoneRepository(array $zones): ZoneRepository
{
    return new class($zones) implements ZoneRepository
    {
        /**
         * @param list<Zone> $zones
         */
        public function __construct(private array $zones) {}

        /**
         * @return list<Zone>
         */
        public function active(): array
        {
            return $this->zones;
        }
    };
}

/**
 * Зона с границей, заданной прямоугольником в градусах.
 */
function zoneWithBoundary(
    string $code,
    float $southWestLatitude,
    float $southWestLongitude,
    float $northEastLatitude,
    float $northEastLongitude,
    bool $isActive = true,
): Zone {
    return new Zone(
        code: $code,
        name: 'Зона '.$code,
        serviceArea: ServiceArea::fromRectangle(
            new Coordinates($southWestLatitude, $southWestLongitude),
            new Coordinates($northEastLatitude, $northEastLongitude),
        ),
        baseFee: Money::rub(19900),
        isActive: $isActive,
    );
}

it('находит единственную зону по координатам', function (): void {
    $zone = zoneWithBoundary('msk-center', 55.70, 37.50, 55.80, 37.60);
    $resolver = new ZoneResolver(fakeZoneRepository([$zone]));

    expect($resolver->resolve(new Coordinates(55.75, 37.55))->code())->toBe('msk-center');
});

it('при пересечении границ выбирает зону меньшей площади', function (): void {
    $big = zoneWithBoundary('msk-big', 55.00, 37.00, 56.00, 38.00);
    $small = zoneWithBoundary('msk-small', 55.70, 37.50, 55.80, 37.60);

    $resolverSmallFirst = new ZoneResolver(fakeZoneRepository([$small, $big]));
    $resolverBigFirst = new ZoneResolver(fakeZoneRepository([$big, $small]));

    $point = new Coordinates(55.75, 37.55);

    expect($resolverSmallFirst->resolve($point)->code())->toBe('msk-small')
        ->and($resolverBigFirst->resolve($point)->code())->toBe('msk-small');
});

it('при равной площади выбирает зону с меньшим кодом', function (): void {
    $first = zoneWithBoundary('zone-b', 55.70, 37.50, 55.80, 37.60);
    $second = zoneWithBoundary('zone-a', 55.70, 37.50, 55.80, 37.60);

    $point = new Coordinates(55.75, 37.55);

    expect((new ZoneResolver(fakeZoneRepository([$first, $second])))->resolve($point)->code())->toBe('zone-a')
        ->and((new ZoneResolver(fakeZoneRepository([$second, $first])))->resolve($point)->code())->toBe('zone-a');
});

it('не зависит от порядка зон при большем числе кандидатов', function (): void {
    $zones = [
        zoneWithBoundary('msk-big', 55.00, 37.00, 56.00, 38.00),
        zoneWithBoundary('msk-middle', 55.60, 37.40, 55.90, 37.70),
        zoneWithBoundary('msk-small', 55.70, 37.50, 55.80, 37.60),
        zoneWithBoundary('msk-equal-b', 55.70, 37.50, 55.80, 37.60),
        zoneWithBoundary('spb-center', 59.80, 30.10, 60.00, 30.50),
    ];

    $point = new Coordinates(55.75, 37.55);
    $codes = [];

    foreach ([$zones, array_reverse($zones), [$zones[2], $zones[4], $zones[0], $zones[3], $zones[1]]] as $order) {
        $codes[] = (new ZoneResolver(fakeZoneRepository($order)))->resolve($point)->code();
    }

    expect(array_unique($codes))->toBe(['msk-equal-b']);
});

it('пропускает неактивную зону', function (): void {
    $inactive = zoneWithBoundary('msk-center', 55.70, 37.50, 55.80, 37.60, isActive: false);
    $active = zoneWithBoundary('msk-wide', 55.00, 37.00, 56.00, 38.00);

    $resolver = new ZoneResolver(fakeZoneRepository([$inactive, $active]));

    expect($resolver->resolve(new Coordinates(55.75, 37.55))->code())->toBe('msk-wide');
});

it('отклоняет адрес вне всех зон', function (): void {
    $zone = zoneWithBoundary('msk-center', 55.70, 37.50, 55.80, 37.60);
    $resolver = new ZoneResolver(fakeZoneRepository([$zone]));

    expect(static fn () => $resolver->resolve(new Coordinates(59.93, 30.31)))
        ->toThrow(AddressOutsideServiceArea::class);
});

it('отклоняет адрес при отсутствии активных зон', function (): void {
    $resolver = new ZoneResolver(fakeZoneRepository([]));

    expect(static fn () => $resolver->resolve(new Coordinates(55.75, 37.55)))
        ->toThrow(AddressOutsideServiceArea::class);
});
