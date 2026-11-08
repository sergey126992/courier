<?php

declare(strict_types=1);

use App\Domain\Geography\Address;
use App\Domain\Geography\Coordinates;
use App\Domain\Geography\Exceptions\InvalidAddress;

it('создаёт адрес с координатами', function (): void {
    $coordinates = new Coordinates(55.755826, 37.6173);
    $address = new Address('Тверской', 'Тверская', '7', $coordinates);

    expect($address->district())->toBe('Тверской')
        ->and($address->street())->toBe('Тверская')
        ->and($address->house())->toBe('7')
        ->and($address->coordinates())->toBe($coordinates);
});

it('обрезает пробелы в полях адреса', function (): void {
    $address = new Address('  Тверской ', ' Тверская  ', ' 7 ', new Coordinates(55.755826, 37.6173));

    expect($address->district())->toBe('Тверской')
        ->and($address->street())->toBe('Тверская')
        ->and($address->house())->toBe('7');
});

it('отклоняет пустые обязательные поля', function (string $district, string $street, string $house, string $field): void {
    expect(static fn () => new Address($district, $street, $house, new Coordinates(55.755826, 37.6173)))
        ->toThrow(InvalidAddress::class, $field);
})->with([
    'район' => ['', 'Тверская', '7', 'район'],
    'улица' => ['Тверской', '   ', '7', 'улица'],
    'дом' => ['Тверской', 'Тверская', '', 'дом'],
]);

it('сравнивает адреса по значению', function (): void {
    $address = new Address('Тверской', 'Тверская', '7', new Coordinates(55.755826, 37.6173));
    $same = new Address('Тверской', 'Тверская', '7', new Coordinates(55.755826, 37.6173));
    $otherStreet = new Address('Тверской', 'Тверская-Ямская', '7', new Coordinates(55.755826, 37.6173));
    $otherPoint = new Address('Тверской', 'Тверская', '7', new Coordinates(55.755827, 37.6173));

    expect($address->equals($same))->toBeTrue()
        ->and($address->equals($otherStreet))->toBeFalse()
        ->and($address->equals($otherPoint))->toBeFalse();
});
