<?php

declare(strict_types=1);

use App\Domain\Geography\Coordinates;
use App\Domain\Geography\Exceptions\InvalidCoordinates;

it('создаёт координаты в допустимом диапазоне', function (): void {
    $coordinates = new Coordinates(55.755826, 37.6173);

    expect($coordinates->latitude())->toBe(55.755826)
        ->and($coordinates->longitude())->toBe(37.6173);
});

it('допускает крайние значения диапазона', function (float $latitude, float $longitude): void {
    $coordinates = new Coordinates($latitude, $longitude);

    expect($coordinates->latitude())->toBe($latitude)
        ->and($coordinates->longitude())->toBe($longitude);
})->with([
    'северный полюс' => [90.0, 0.0],
    'южный полюс' => [-90.0, 0.0],
    'западная граница' => [0.0, -180.0],
    'восточная граница' => [0.0, 180.0],
]);

it('отклоняет широту вне диапазона', function (float $latitude): void {
    expect(static fn () => new Coordinates($latitude, 37.6173))
        ->toThrow(InvalidCoordinates::class, 'Широта');
})->with([
    'севернее предела' => [90.000001],
    'южнее предела' => [-90.000001],
]);

it('отклоняет долготу вне диапазона', function (float $longitude): void {
    expect(static fn () => new Coordinates(55.755826, $longitude))
        ->toThrow(InvalidCoordinates::class, 'Долгота');
})->with([
    'восточнее предела' => [180.000001],
    'западнее предела' => [-180.000001],
]);

it('отклоняет координату точнее шести знаков', function (float $latitude, float $longitude, string $fragment): void {
    expect(static fn () => new Coordinates($latitude, $longitude))
        ->toThrow(InvalidCoordinates::class, $fragment);
})->with([
    'широта' => [55.7558265, 37.6173, 'широта'],
    'долгота' => [55.755826, 37.6173001, 'долгота'],
]);

it('допускает координату ровно с шестью знаками', function (): void {
    expect((new Coordinates(55.755826, 37.617299))->longitude())->toBe(37.617299);
});

it('сравнивает координаты по значению', function (): void {
    $coordinates = new Coordinates(55.755826, 37.6173);

    expect($coordinates->equals(new Coordinates(55.755826, 37.6173)))->toBeTrue()
        ->and($coordinates->equals(new Coordinates(55.755827, 37.6173)))->toBeFalse()
        ->and($coordinates->equals(new Coordinates(55.755826, 37.6174)))->toBeFalse();
});

it('отдаёт структуру для хранения и обмена', function (): void {
    expect((new Coordinates(55.755826, 37.6173))->toArray())
        ->toBe(['latitude' => 55.755826, 'longitude' => 37.6173]);
});
