<?php

declare(strict_types=1);

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;

it('даёт разный прогноз для четырёх видов лёгкого транспорта', function (): void {
    $etas = [];

    foreach (LightVehicleKind::cases() as $kind) {
        $etas[$kind->value] = pricingStrategy(TransportType::Light)
            ->estimate(pricingInput(TransportType::Light, $kind, distanceKm: 2.0))
            ->etaMinutes();
    }

    expect($etas)->toHaveCount(4)
        ->and(array_unique($etas))->toHaveCount(4);

    // Чем выше скорость вида, тем меньше прогноз: педальный самокат медленнее электровелосипеда.
    expect($etas['scooter'])->toBeGreaterThan($etas['bicycle'])
        ->and($etas['bicycle'])->toBeGreaterThan($etas['e_scooter'])
        ->and($etas['e_scooter'])->toBeGreaterThan($etas['e_bike']);
});
