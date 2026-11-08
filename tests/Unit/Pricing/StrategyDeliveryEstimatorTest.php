<?php

declare(strict_types=1);

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Pricing\BreakdownCode;
use App\Domain\Pricing\BreakdownLine;
use App\Domain\Pricing\Contracts\DeliveryEstimateStrategy;
use App\Domain\Pricing\Distance;
use App\Domain\Pricing\EstimateInput;
use App\Domain\Pricing\Exceptions\UnsupportedTransport;
use App\Domain\Pricing\Money;
use App\Domain\Pricing\PriceQuote;
use App\Domain\Pricing\StrategyDeliveryEstimator;

/**
 * Стратегия-заглушка для проверки расширяемости: поддерживает всё и возвращает одну строку.
 */
function pricingStubStrategy(): DeliveryEstimateStrategy
{
    return new class implements DeliveryEstimateStrategy
    {
        public function supports(EstimateInput $input): bool
        {
            return true;
        }

        public function estimate(EstimateInput $input): PriceQuote
        {
            return new PriceQuote(
                transport: $input->transport(),
                lightVehicleKind: $input->lightVehicleKind(),
                distance: $input->distance(),
                etaMinutes: 1,
                total: Money::rub(100),
                lines: [new BreakdownLine(BreakdownCode::Base, 'Заглушка', Money::rub(100))],
            );
        }
    };
}

it('выбирает стратегию своего транспорта', function (): void {
    $estimator = new StrategyDeliveryEstimator([
        pricingStrategy(TransportType::OnFoot),
        pricingStrategy(TransportType::Light),
        pricingStrategy(TransportType::Car),
    ]);

    $quote = $estimator->estimate(pricingInput(TransportType::Light, LightVehicleKind::EBike));

    expect($quote->transport())->toBe(TransportType::Light)
        ->and($quote->etaMinutes())->toBeGreaterThan(0);
});

it('бросает исключение, если ни одна стратегия не подходит', function (): void {
    $estimator = new StrategyDeliveryEstimator([pricingStrategy(TransportType::Car)]);

    try {
        $estimator->estimate(pricingInput(TransportType::OnFoot));
        $this->fail('Ожидалось исключение о неподдерживаемом транспорте.');
    } catch (UnsupportedTransport $exception) {
        expect($exception->transport())->toBe(TransportType::OnFoot);
    }
});

it('работает с новым набором стратегий без правки вызывающего кода', function (): void {
    $calculate = static function (EstimateInput $input): PriceQuote {
        return (new StrategyDeliveryEstimator([pricingStubStrategy()]))->estimate($input);
    };

    $quote = $calculate(pricingInput(TransportType::Car, distanceKm: 1.5));

    expect($quote->total()->amount())->toBe(100)
        ->and($quote->lines())->toHaveCount(1)
        ->and($quote->distance())->toEqual(Distance::fromKilometers(1.5));
});
