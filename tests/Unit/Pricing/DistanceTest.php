<?php

declare(strict_types=1);

use App\Domain\Pricing\Distance;
use App\Domain\Pricing\Exceptions\InvalidDistance;

it('создаёт расстояние с точностью до десятых', function (): void {
    $distance = Distance::fromKilometers(4.2);

    expect($distance->kilometers())->toBe(4.2)
        ->and($distance->label())->toBe('4.2 км');
});

it('допускает нулевое расстояние', function (): void {
    expect(Distance::fromKilometers(0.0)->kilometers())->toBe(0.0);
});

it('отклоняет отрицательное расстояние', function (): void {
    expect(static fn () => Distance::fromKilometers(-0.1))
        ->toThrow(InvalidDistance::class);
});

it('хранит расстояние с точностью до сотых', function (): void {
    $distance = Distance::fromKilometers(4.25);

    expect($distance->kilometers())->toBe(4.25)
        ->and($distance->label())->toBe('4.25 км');
});

it('отклоняет расстояние точнее 0.01 км', function (): void {
    expect(static fn () => Distance::fromKilometers(1.234))
        ->toThrow(InvalidDistance::class);
});

it('сравнивает расстояние с пределом без превышения на равенстве', function (): void {
    $distance = Distance::fromKilometers(2.0);

    expect($distance->exceeds(2.0))->toBeFalse()
        ->and($distance->exceeds(1.9))->toBeTrue();
});
