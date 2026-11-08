<?php

declare(strict_types=1);

use App\Domain\Identity\TransportType;

it('содержит все типы транспорта этапа', function (): void {
    $values = array_map(
        static fn (TransportType $type): string => $type->value,
        TransportType::cases(),
    );

    expect($values)->toBe(['on_foot', 'light', 'car']);
});

it('выдаёт название типа транспорта', function (string $value, string $label): void {
    expect(TransportType::from($value)->label())->toBe($label);
})->with([
    ['on_foot', 'Пешком'],
    ['light', 'Лёгкий транспорт'],
    ['car', 'Автомобиль'],
]);

it('определяет лёгкий транспорт', function (TransportType $type, bool $expected): void {
    expect($type->isLight())->toBe($expected);
})->with([
    'пешком' => [TransportType::OnFoot, false],
    'лёгкий' => [TransportType::Light, true],
    'автомобиль' => [TransportType::Car, false],
]);

it('определяет потребность в автомобиле', function (TransportType $type, bool $expected): void {
    expect($type->requiresVehicle())->toBe($expected);
})->with([
    'пешком' => [TransportType::OnFoot, false],
    'лёгкий' => [TransportType::Light, false],
    'автомобиль' => [TransportType::Car, true],
]);

it('не содержит пустых названий', function (): void {
    foreach (TransportType::cases() as $type) {
        expect(trim($type->label()))->not->toBe('');
    }
});
