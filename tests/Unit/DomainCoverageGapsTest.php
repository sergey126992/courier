<?php

declare(strict_types=1);

use App\Domain\Dispatch\AssignmentStrategy;
use App\Domain\Dispatch\CourierCandidates;
use App\Domain\Geography\Coordinates;
use App\Domain\Geography\Exceptions\InvalidServiceArea;
use App\Domain\Geography\ServiceArea;
use App\Domain\Identity\LightVehicleKind;

/*
|--------------------------------------------------------------------------
| Непокрытые геттеры и края домена монолита (порог домена 100 %)
|--------------------------------------------------------------------------
|
| Тесты закрывают ветки, до которых не доходили сценарные прогоны:
| пустой результат подбора, неизвестный тип границы зоны, точка на
| вырожденном ребре многоугольника и вид лёгкого транспорта в заказе.
|
*/

it('сообщает, что кандидатов на назначение нет', function (): void {
    $candidates = new CourierCandidates(AssignmentStrategy::Nearest, 15, []);

    expect($candidates->isEmpty())->toBeTrue();
});

it('отклоняет границу многоугольника без списка точек', function (): void {
    expect(static fn (): ServiceArea => ServiceArea::fromArray(['type' => 'polygon', 'points' => 'нет']))
        ->toThrow(InvalidServiceArea::class);
});

it('обходит вырожденное ребро многоугольника при проверке попадания', function (): void {
    $area = ServiceArea::fromPoints([
        new Coordinates(55.70, 37.50),
        new Coordinates(55.70, 37.60),
        new Coordinates(55.80, 37.55),
        new Coordinates(55.80, 37.55),
    ]);

    expect($area->contains(new Coordinates(55.72, 37.55)))->toBeTrue();
});

it('отдаёт вид лёгкого транспорта из зафиксированного расчёта', function (): void {
    $order = orderAggregate();

    expect($order->lightVehicleKind())->toBeInstanceOf(LightVehicleKind::class);
});
