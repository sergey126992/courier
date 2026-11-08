<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Strategies;

use App\Domain\Identity\TransportType;
use App\Domain\Pricing\BreakdownCode;
use App\Domain\Pricing\Contracts\DeliveryEstimateStrategy;
use App\Domain\Pricing\EstimateCalculator;
use App\Domain\Pricing\EstimateInput;
use App\Domain\Pricing\PriceQuote;
use App\Domain\Pricing\Surcharge;
use App\Domain\Pricing\TransportParams;

/**
 * Расчёт для автомобиля: поправка на час пик в скорости и надбавке,
 * платная парковка по признаку во входных данных.
 */
final readonly class CarEstimateStrategy implements DeliveryEstimateStrategy
{
    public function __construct(
        private EstimateCalculator $calculator,
        private TransportParams $params,
    ) {}

    public function supports(EstimateInput $input): bool
    {
        return $input->transport() === TransportType::Car;
    }

    public function estimate(EstimateInput $input): PriceQuote
    {
        // Строка надбавки за час пик присутствует всегда: вне часа пик она нулевая.
        $percent = $this->params->isPeakHour($input->requestedAt())
            ? $this->params->peakSurchargePercent()
            : 0.0;

        $surcharges = [
            Surcharge::percentage(BreakdownCode::Surge, BreakdownCode::Surge->label(), $percent),
        ];

        $parkingFee = $this->params->parkingFee();

        if ($parkingFee !== null && $input->isPaidParking()) {
            $surcharges[] = Surcharge::fixed(
                BreakdownCode::Parking,
                BreakdownCode::Parking->label(),
                $parkingFee,
            );
        }

        return $this->calculator->quote($input, $this->params, $surcharges);
    }
}
