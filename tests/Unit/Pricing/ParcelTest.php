<?php

declare(strict_types=1);

use App\Domain\Pricing\Exceptions\InvalidParcel;
use App\Domain\Pricing\Money;
use App\Domain\Pricing\Parcel;

it('создаёт корректную посылку', function (): void {
    $parcel = new Parcel(1500, 30, 20, 10, Money::rub(500000));

    expect($parcel->weightGrams())->toBe(1500)
        ->and($parcel->lengthCm())->toBe(30)
        ->and($parcel->widthCm())->toBe(20)
        ->and($parcel->heightCm())->toBe(10)
        ->and($parcel->declaredValue()?->amount())->toBe(500000);
});

it('допускает отсутствие заявленной стоимости', function (): void {
    expect((new Parcel(1500, 30, 20, 10))->declaredValue())->toBeNull();
});

it('отклоняет нулевой и отрицательный вес', function (int $weightGrams): void {
    expect(static fn () => new Parcel($weightGrams, 30, 20, 10))
        ->toThrow(InvalidParcel::class);
})->with([
    'ноль' => [0],
    'отрицательный' => [-100],
]);

it('отклоняет неположительные габариты', function (int $length, int $width, int $height, string $fragment): void {
    expect(static fn () => new Parcel(1500, $length, $width, $height))
        ->toThrow(InvalidParcel::class, $fragment);
})->with([
    'длина' => [0, 20, 10, 'длина'],
    'ширина' => [30, -1, 10, 'ширина'],
    'высота' => [30, 20, 0, 'высота'],
]);

it('сравнивает вес с пределом без превышения на равенстве', function (): void {
    $parcel = new Parcel(3000, 30, 20, 10);

    expect($parcel->exceedsWeight(3000))->toBeFalse()
        ->and($parcel->exceedsWeight(2999))->toBeTrue();
});
