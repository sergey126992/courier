<?php

declare(strict_types=1);

use App\Domain\Geography\Coordinates;
use App\Domain\Geography\ServiceArea;
use App\Domain\Geography\Zone;
use App\Domain\Pricing\Money;

/**
 * Зона-прямоугольник 55.70…55.80 по широте и 37.50…37.60 по долготе.
 */
function zoneForTest(string $code = 'msk-center', bool $isActive = true): Zone
{
    return new Zone(
        code: $code,
        name: 'Центр',
        serviceArea: ServiceArea::fromRectangle(
            new Coordinates(55.70, 37.50),
            new Coordinates(55.80, 37.60),
        ),
        baseFee: Money::rub(19900),
        isActive: $isActive,
    );
}

it('отдаёт значения зоны', function (): void {
    $zone = zoneForTest();

    expect($zone->code())->toBe('msk-center')
        ->and($zone->name())->toBe('Центр')
        ->and($zone->baseFee()->amount())->toBe(19900)
        ->and($zone->baseFee()->currency())->toBe('RUB')
        ->and($zone->isActive())->toBeTrue()
        ->and($zone->serviceArea()->type())->toBe(ServiceArea::TYPE_RECTANGLE);
});

it('по умолчанию зона активна', function (): void {
    $zone = new Zone(
        code: 'msk-center',
        name: 'Центр',
        serviceArea: ServiceArea::fromRectangle(new Coordinates(55.70, 37.50), new Coordinates(55.80, 37.60)),
        baseFee: Money::rub(19900),
    );

    expect($zone->isActive())->toBeTrue();
});

it('определяет попадание точки в границу', function (): void {
    $zone = zoneForTest();

    expect($zone->contains(new Coordinates(55.75, 37.55)))->toBeTrue()
        ->and($zone->contains(new Coordinates(55.65, 37.55)))->toBeFalse();
});

it('отдаёт площадь своей границы', function (): void {
    $zone = zoneForTest();

    expect($zone->area())->toBeGreaterThan(69.0)->toBeLessThan(70.5);
});
