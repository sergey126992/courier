<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Strategies;

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Pricing\BreakdownCode;
use App\Domain\Pricing\Contracts\DeliveryEstimateStrategy;
use App\Domain\Pricing\EstimateCalculator;
use App\Domain\Pricing\EstimateInput;
use App\Domain\Pricing\PriceQuote;
use App\Domain\Pricing\Surcharge;
use App\Domain\Pricing\TransportParams;

/**
 * Расчёт для лёгкого транспорта: скорость зависит от вида,
 * надбавка за час пик действует только для самокатов.
 */
final readonly class LightTransportEstimateStrategy implements DeliveryEstimateStrategy
{
    /**
     * Виды транспорта, для которых действует надбавка за час пик.
     *
     * @var list<LightVehicleKind>
     */
    private const PEAK_SURCHARGE_KINDS = [
        LightVehicleKind::Scooter,
        LightVehicleKind::EScooter,
    ];

    public function __construct(
        private EstimateCalculator $calculator,
        private TransportParams $params,
    ) {}

    public function supports(EstimateInput $input): bool
    {
        return $input->transport() === TransportType::Light;
    }

    public function estimate(EstimateInput $input): PriceQuote
    {
        // Строка надбавки присутствует всегда: вне часа пик и для других видов она нулевая.
        $percent = $this->isPeakSurchargeApplicable($input)
            ? $this->params->peakSurchargePercent()
            : 0.0;

        return $this->calculator->quote($input, $this->params, [
            Surcharge::percentage(BreakdownCode::Surge, BreakdownCode::Surge->label(), $percent),
        ]);
    }

    /**
     * Надбавка за час пик: самокат или электросамокат в интервале часа пик.
     */
    private function isPeakSurchargeApplicable(EstimateInput $input): bool
    {
        $kind = $input->lightVehicleKind();

        return $kind !== null
            && in_array($kind, self::PEAK_SURCHARGE_KINDS, true)
            && $this->params->isPeakHour($input->requestedAt());
    }
}
