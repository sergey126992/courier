<?php

declare(strict_types=1);

use App\Domain\Geography\Coordinates;
use App\Domain\Geography\Exceptions\InvalidServiceArea;
use App\Domain\Geography\ServiceArea;

/**
 * Прямоугольник вокруг центра Москвы: 55.70…55.80 по широте, 37.50…37.60 по долготе.
 */
function rectangleArea(): ServiceArea
{
    return ServiceArea::fromRectangle(
        new Coordinates(55.70, 37.50),
        new Coordinates(55.80, 37.60),
    );
}

/**
 * Квадрат-многоугольник с теми же границами, что и rectangleArea().
 */
function squarePolygon(): ServiceArea
{
    return ServiceArea::fromPoints([
        new Coordinates(55.70, 37.50),
        new Coordinates(55.70, 37.60),
        new Coordinates(55.80, 37.60),
        new Coordinates(55.80, 37.50),
    ]);
}

it('находит точку внутри прямоугольника', function (): void {
    expect(rectangleArea()->contains(new Coordinates(55.75, 37.55)))->toBeTrue();
});

it('не находит точку снаружи прямоугольника', function (float $latitude, float $longitude): void {
    expect(rectangleArea()->contains(new Coordinates($latitude, $longitude)))->toBeFalse();
})->with([
    'западнее' => [55.75, 37.45],
    'восточнее' => [55.75, 37.65],
    'южнее' => [55.65, 37.55],
    'севернее' => [55.85, 37.55],
]);

it('считает точку на ребре и в углу прямоугольника попаданием', function (float $latitude, float $longitude): void {
    expect(rectangleArea()->contains(new Coordinates($latitude, $longitude)))->toBeTrue();
})->with([
    'западное ребро' => [55.75, 37.50],
    'северное ребро' => [55.80, 37.55],
    'юго-западный угол' => [55.70, 37.50],
    'северо-восточный угол' => [55.80, 37.60],
]);

it('считает площадь прямоугольника в квадратных километрах', function (): void {
    // 0.1° по широте ≈ 11.13 км, 0.1° по долготе на 55.75° ≈ 6.26 км.
    expect(rectangleArea()->area())->toBeGreaterThan(69.0)->toBeLessThan(70.5);
});

it('находит точку внутри многоугольника', function (): void {
    expect(squarePolygon()->contains(new Coordinates(55.75, 37.55)))->toBeTrue();
});

it('не находит точку снаружи многоугольника', function (): void {
    expect(squarePolygon()->contains(new Coordinates(55.75, 37.45)))->toBeFalse()
        ->and(squarePolygon()->contains(new Coordinates(55.85, 37.55)))->toBeFalse();
});

it('считает точку на ребре и в вершине многоугольника попаданием', function (float $latitude, float $longitude): void {
    expect(squarePolygon()->contains(new Coordinates($latitude, $longitude)))->toBeTrue();
})->with([
    'западное ребро' => [55.75, 37.50],
    'восточное ребро' => [55.75, 37.60],
    'южное ребро' => [55.70, 37.55],
    'северное ребро' => [55.80, 37.55],
    'вершина' => [55.80, 37.50],
]);

it('считает площадь многоугольника в квадратных километрах', function (): void {
    // Площадь квадрата и прямоугольника с теми же границами совпадает.
    expect(squarePolygon()->area())->toBeGreaterThan(69.0)->toBeLessThan(70.5)
        ->and(squarePolygon()->area())->toEqualWithDelta(rectangleArea()->area(), 0.01);
});

it('считает площадь треугольника в половину прямоугольника', function (): void {
    $triangle = ServiceArea::fromPoints([
        new Coordinates(55.70, 37.50),
        new Coordinates(55.70, 37.60),
        new Coordinates(55.80, 37.50),
    ]);

    // Плоская проекция опирается на среднюю широту фигуры, поэтому площади
    // треугольника и половины прямоугольника совпадают приблизительно.
    expect($triangle->area())->toEqualWithDelta(rectangleArea()->area() / 2, 0.1);
});

it('отклоняет прямоугольник с обратным порядком углов', function (): void {
    expect(static fn () => ServiceArea::fromRectangle(
        new Coordinates(55.80, 37.60),
        new Coordinates(55.70, 37.50),
    ))->toThrow(InvalidServiceArea::class);
});

it('отклоняет многоугольник из менее чем трёх точек', function (): void {
    expect(static fn () => ServiceArea::fromPoints([
        new Coordinates(55.70, 37.50),
        new Coordinates(55.80, 37.60),
    ]))->toThrow(InvalidServiceArea::class, 'трёх точек');
});

it('отклоняет вырожденный многоугольник на одной прямой', function (): void {
    expect(static fn () => ServiceArea::fromPoints([
        new Coordinates(55.70, 37.50),
        new Coordinates(55.75, 37.55),
        new Coordinates(55.80, 37.60),
    ]))->toThrow(InvalidServiceArea::class, 'вырождена');
});

it('строит прямоугольник из структуры хранения', function (): void {
    $area = ServiceArea::fromArray([
        'type' => 'rectangle',
        'south_west' => ['latitude' => 55.70, 'longitude' => 37.50],
        'north_east' => ['latitude' => 55.80, 'longitude' => 37.60],
    ]);

    expect($area->type())->toBe(ServiceArea::TYPE_RECTANGLE)
        ->and($area->contains(new Coordinates(55.75, 37.55)))->toBeTrue();
});

it('строит многоугольник из структуры хранения', function (): void {
    $area = ServiceArea::fromArray([
        'type' => 'polygon',
        'points' => [
            ['latitude' => 55.70, 'longitude' => 37.50],
            ['latitude' => 55.70, 'longitude' => 37.60],
            ['latitude' => 55.80, 'longitude' => 37.60],
        ],
    ]);

    expect($area->type())->toBe(ServiceArea::TYPE_POLYGON)
        ->and($area->points())->toHaveCount(3)
        ->and($area->contains(new Coordinates(55.72, 37.52)))->toBeTrue();
});

it('отклоняет структуру границы неизвестного типа', function (): void {
    expect(static fn () => ServiceArea::fromArray(['type' => 'circle']))
        ->toThrow(InvalidServiceArea::class);
});

it('отклоняет точку границы без координат', function (): void {
    expect(static fn () => ServiceArea::fromArray([
        'type' => 'rectangle',
        'south_west' => ['latitude' => 'север'],
        'north_east' => ['latitude' => 55.80, 'longitude' => 37.60],
    ]))->toThrow(InvalidServiceArea::class);
});

it('возвращает ту же структуру при обратном превращении', function (): void {
    $boundary = [
        'type' => 'polygon',
        'points' => [
            ['latitude' => 55.70, 'longitude' => 37.50],
            ['latitude' => 55.70, 'longitude' => 37.60],
            ['latitude' => 55.80, 'longitude' => 37.60],
        ],
    ];

    expect(ServiceArea::fromArray($boundary)->toArray())->toBe($boundary);
});

it('отдаёт прямоугольник в форме latitude/longitude', function (): void {
    $boundary = [
        'type' => 'rectangle',
        'south_west' => ['latitude' => 55.70, 'longitude' => 37.50],
        'north_east' => ['latitude' => 55.80, 'longitude' => 37.60],
    ];

    expect(ServiceArea::fromArray($boundary)->toArray())->toBe($boundary);
});

it('отклоняет старую форму координат lat/lon', function (): void {
    expect(static fn () => ServiceArea::fromArray([
        'type' => 'rectangle',
        'south_west' => ['lat' => 55.70, 'lon' => 37.50],
        'north_east' => ['lat' => 55.80, 'lon' => 37.60],
    ]))->toThrow(InvalidServiceArea::class);
});
