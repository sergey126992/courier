<?php

declare(strict_types=1);

use App\Domain\Geography\Coordinates;
use App\Services\Geography\HaversineDistanceProvider;

it('даёт нулевое расстояние для одной и той же точки', function (): void {
    $provider = new HaversineDistanceProvider(1.3);
    $point = new Coordinates(55.755826, 37.6173);

    expect($provider->between($point, $point)->kilometers())->toBe(0.0);
});

it('считает известное расстояние по прямой', function (): void {
    $provider = new HaversineDistanceProvider(1.0);
    $from = new Coordinates(55.0, 37.0);
    $to = new Coordinates(56.0, 37.0);

    // Один градус широты по среднему радиусу Земли (6371.0088 км) — 111.20 км.
    expect($provider->between($from, $to)->kilometers())->toBe(111.2);
});

it('применяет коэффициент удлинения маршрута', function (): void {
    $from = new Coordinates(55.0, 37.0);
    $to = new Coordinates(56.0, 37.0);

    expect((new HaversineDistanceProvider(1.0))->between($from, $to)->kilometers())->toBe(111.2)
        ->and((new HaversineDistanceProvider(1.3))->between($from, $to)->kilometers())->toBe(144.55);
});

it('считает расстояние между городами', function (): void {
    $provider = new HaversineDistanceProvider(1.0);

    $distance = $provider->between(
        new Coordinates(55.755826, 37.6173),
        new Coordinates(59.938784, 30.314997),
    );

    expect($distance->kilometers())->toBe(634.17);
});

it('не зависит от направления пары', function (): void {
    $provider = new HaversineDistanceProvider(1.3);
    $moscow = new Coordinates(55.755826, 37.6173);
    $spb = new Coordinates(59.938784, 30.314997);

    expect($provider->between($moscow, $spb)->kilometers())
        ->toBe($provider->between($spb, $moscow)->kilometers());
});

it('отклоняет неположительный коэффициент удлинения', function (float $factor): void {
    expect(static fn () => new HaversineDistanceProvider($factor))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'ноль' => [0.0],
    'отрицательный' => [-1.3],
]);
