<?php

declare(strict_types=1);

use App\Domain\Identity\Exceptions\InvalidTransportCombination;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;

it('допускает любой вид лёгкого транспорта при типе «лёгкий»', function (LightVehicleKind $kind): void {
    expect(static fn () => TransportType::Light->assertAccepts($kind))
        ->not->toThrow(InvalidTransportCombination::class);
})->with(array_map(static fn (LightVehicleKind $kind): array => [$kind], LightVehicleKind::cases()));

it('допускает отсутствие вида транспорта для любого типа', function (TransportType $type): void {
    expect(static fn () => $type->assertAccepts(null))
        ->not->toThrow(InvalidTransportCombination::class);
})->with(array_map(static fn (TransportType $type): array => [$type], TransportType::cases()));

it('запрещает вид лёгкого транспорта для других типов', function (TransportType $type, LightVehicleKind $kind): void {
    expect(static fn () => $type->assertAccepts($kind))
        ->toThrow(InvalidTransportCombination::class);
})->with([
    'пешком, велосипед' => [TransportType::OnFoot, LightVehicleKind::Bicycle],
    'пешком, электросамокат' => [TransportType::OnFoot, LightVehicleKind::EScooter],
    'автомобиль, самокат' => [TransportType::Car, LightVehicleKind::Scooter],
    'автомобиль, электровелосипед' => [TransportType::Car, LightVehicleKind::EBike],
]);

it('объясняет недопустимое сочетание на русском', function (): void {
    expect(static fn () => TransportType::Car->assertAccepts(LightVehicleKind::Bicycle))
        ->toThrow(
            InvalidTransportCombination::class,
            'Вид лёгкого транспорта «Велосипед» недопустим для типа транспорта «Автомобиль».',
        );
});
