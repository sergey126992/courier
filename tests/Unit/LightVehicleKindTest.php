<?php

declare(strict_types=1);

use App\Domain\Identity\LightVehicleKind;

it('содержит все виды лёгкого транспорта этапа', function (): void {
    $values = array_map(
        static fn (LightVehicleKind $kind): string => $kind->value,
        LightVehicleKind::cases(),
    );

    expect($values)->toBe(['bicycle', 'scooter', 'e_scooter', 'e_bike']);
});

it('выдаёт название вида лёгкого транспорта', function (string $value, string $label): void {
    expect(LightVehicleKind::from($value)->label())->toBe($label);
})->with([
    ['bicycle', 'Велосипед'],
    ['scooter', 'Самокат'],
    ['e_scooter', 'Электросамокат'],
    ['e_bike', 'Электровелосипед'],
]);

it('не содержит пустых названий', function (): void {
    foreach (LightVehicleKind::cases() as $kind) {
        expect(trim($kind->label()))->not->toBe('');
    }
});
