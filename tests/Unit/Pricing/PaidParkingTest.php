<?php

declare(strict_types=1);

use App\Domain\Identity\TransportType;
use App\Domain\Pricing\BreakdownCode;

it('добавляет строку парковки автомобилю при платной парковке', function (): void {
    $params = pricingParams(TransportType::Car);
    $quote = pricingStrategy(TransportType::Car)->estimate(pricingInput(
        TransportType::Car,
        distanceKm: 2.0,
        requestedAt: pricingAt('2026-03-10 09:00'),
        isPaidParking: true,
    ));
    $parkingFee = $params->parkingFee();

    expect($parkingFee)->not->toBeNull()
        ->and(pricingLineAmount($quote, BreakdownCode::Parking))->toBe($parkingFee?->amount())
        ->and($quote->total()->amount())->toBe(
            pricingLineAmount($quote, BreakdownCode::Base)
            + pricingLineAmount($quote, BreakdownCode::Distance)
            + pricingLineAmount($quote, BreakdownCode::Surge)
            + pricingLineAmount($quote, BreakdownCode::Parking)
        );
});

it('не добавляет строку парковки без признака платной парковки', function (): void {
    $quote = pricingStrategy(TransportType::Car)->estimate(pricingInput(TransportType::Car));

    expect(pricingLine($quote, BreakdownCode::Parking))->toBeNull();
});

it('не добавляет строку парковки другим видам транспорта', function (TransportType $transport): void {
    $quote = pricingStrategy($transport)->estimate(pricingInput($transport, isPaidParking: true));

    expect(pricingLine($quote, BreakdownCode::Parking))->toBeNull();
})->with([
    'пеший' => [TransportType::OnFoot],
    'лёгкий транспорт' => [TransportType::Light],
]);
