<?php

declare(strict_types=1);

use App\Domain\Identity\Exceptions\InvalidTransportCombination;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Pricing\Distance;
use App\Domain\Pricing\EstimateInput;
use App\Domain\Pricing\Parcel;

it('собирает входные данные расчёта', function (): void {
    $requestedAt = pricingAt();
    $distance = Distance::fromKilometers(4.2);
    $parcel = new Parcel(1500, 30, 20, 10);

    $input = new EstimateInput(
        transport: TransportType::Light,
        lightVehicleKind: LightVehicleKind::EScooter,
        distance: $distance,
        parcel: $parcel,
        requestedAt: $requestedAt,
        isPaidParking: true,
    );

    expect($input->transport())->toBe(TransportType::Light)
        ->and($input->lightVehicleKind())->toBe(LightVehicleKind::EScooter)
        ->and($input->distance())->toBe($distance)
        ->and($input->parcel())->toBe($parcel)
        ->and($input->requestedAt())->toEqual($requestedAt)
        ->and($input->isPaidParking())->toBeTrue();
});

it('по умолчанию считает парковку бесплатной', function (): void {
    expect(pricingInput(TransportType::Car)->isPaidParking())->toBeFalse();
});

it('отклоняет вид транспорта при неподходящем типе', function (): void {
    expect(static fn () => new EstimateInput(
        transport: TransportType::Car,
        lightVehicleKind: LightVehicleKind::Bicycle,
        distance: Distance::fromKilometers(4.2),
        parcel: new Parcel(1500, 30, 20, 10),
        requestedAt: pricingAt(),
    ))->toThrow(InvalidTransportCombination::class);
});
