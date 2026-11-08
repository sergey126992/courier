<?php

declare(strict_types=1);

use App\Domain\Identity\TransportType;
use App\Domain\Pricing\BreakdownCode;
use App\Domain\Pricing\EstimateCalculator;
use App\Domain\Pricing\Exceptions\ParcelOutsideDeliveryRadius;
use App\Domain\Pricing\Exceptions\ParcelTooHeavyForTransport;
use App\Domain\Pricing\Money;
use App\Domain\Pricing\Surcharge;

it('считает базовую ставку и расстояние без надбавок', function (): void {
    $input = pricingInput(TransportType::OnFoot, distanceKm: 2.0);
    $quote = (new EstimateCalculator)->quote($input, pricingParams(TransportType::OnFoot));

    expect(pricingLineAmount($quote, BreakdownCode::Base))->toBe(9900)
        ->and(pricingLineAmount($quote, BreakdownCode::Distance))->toBe(4000)
        ->and(pricingLine($quote, BreakdownCode::Distance)?->label())->toBe('2.0 км')
        ->and($quote->total()->amount())->toBe(13900)
        ->and($quote->lines())->toHaveCount(2);
});

it('считает прогноз как путь ÷ скорость плюс время на операции', function (): void {
    $input = pricingInput(TransportType::OnFoot, distanceKm: 2.0);

    // 2 км ÷ 4.5 км/ч × 60 = 27 минут пути, плюс 10 минут на операции.
    expect((new EstimateCalculator)->quote($input, pricingParams(TransportType::OnFoot))->etaMinutes())->toBe(37);
});

it('добавляет процентную и фиксированную надбавки', function (): void {
    $input = pricingInput(TransportType::Car, distanceKm: 2.0, isPaidParking: true);
    $quote = (new EstimateCalculator)->quote($input, pricingParams(TransportType::Car), [
        Surcharge::percentage(BreakdownCode::Surge, 'Надбавка за час пик', 0.15),
        Surcharge::fixed(BreakdownCode::Parking, 'Надбавка за парковку', Money::rub(5000)),
    ]);

    // 24900 + 7000 = 31900; 15% от 31900 = 4785; парковка 5000.
    expect(pricingLineAmount($quote, BreakdownCode::Surge))->toBe(4785)
        ->and(pricingLineAmount($quote, BreakdownCode::Parking))->toBe(5000)
        ->and($quote->total()->amount())->toBe(31900 + 4785 + 5000);
});

it('считает нулевое расстояние без времени в пути', function (): void {
    $input = pricingInput(TransportType::OnFoot, distanceKm: 0.0);
    $quote = (new EstimateCalculator)->quote($input, pricingParams(TransportType::OnFoot));

    expect(pricingLine($quote, BreakdownCode::Distance)?->amount()->isZero())->toBeTrue()
        ->and($quote->total()->amount())->toBe(9900)
        ->and($quote->etaMinutes())->toBe(10);
});

it('замедляется в час пик', function (): void {
    $calculator = new EstimateCalculator;
    $params = pricingParams(TransportType::Car);

    $offPeak = $calculator->quote(pricingInput(TransportType::Car), $params);
    $peak = $calculator->quote(
        pricingInput(TransportType::Car, requestedAt: pricingAt('2026-03-10 09:00')),
        $params,
    );

    expect($peak->etaMinutes())->toBeGreaterThan($offPeak->etaMinutes());
});

it('отклоняет посылку тяжелее предела транспорта', function (): void {
    $params = pricingParams(TransportType::OnFoot);
    $input = pricingInput(TransportType::OnFoot, weightGrams: $params->maxWeightGrams() + 1);

    try {
        (new EstimateCalculator)->quote($input, $params);
        $this->fail('Ожидалось исключение о превышении веса.');
    } catch (ParcelTooHeavyForTransport $exception) {
        expect($exception->maxWeightGrams())->toBe($params->maxWeightGrams())
            ->and($exception->transport())->toBe(TransportType::OnFoot);
    }
});

it('отклоняет маршрут вне радиуса обслуживания', function (): void {
    $params = pricingParams(TransportType::Car);
    $input = pricingInput(TransportType::Car, distanceKm: $params->maxRadiusKm() + 0.1);

    expect(static fn () => (new EstimateCalculator)->quote($input, $params))
        ->toThrow(ParcelOutsideDeliveryRadius::class);
});
