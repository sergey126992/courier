<?php

declare(strict_types=1);

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;

it('даёт три разных результата на одном маршруте и пеший медленнее автомобиля', function (): void {
    $onFoot = pricingStrategy(TransportType::OnFoot)
        ->estimate(pricingInput(TransportType::OnFoot, distanceKm: 1.5, weightGrams: 1500));
    $light = pricingStrategy(TransportType::Light)
        ->estimate(pricingInput(TransportType::Light, LightVehicleKind::EBike, distanceKm: 1.5, weightGrams: 1500));
    $car = pricingStrategy(TransportType::Car)
        ->estimate(pricingInput(TransportType::Car, distanceKm: 1.5, weightGrams: 1500));

    $totals = [$onFoot->total()->amount(), $light->total()->amount(), $car->total()->amount()];
    $etas = [$onFoot->etaMinutes(), $light->etaMinutes(), $car->etaMinutes()];

    expect(array_unique($totals))->toHaveCount(3)
        ->and(array_unique($etas))->toHaveCount(3)
        ->and($onFoot->etaMinutes())->toBeGreaterThan($light->etaMinutes())
        ->and($light->etaMinutes())->toBeGreaterThan($car->etaMinutes());
});
