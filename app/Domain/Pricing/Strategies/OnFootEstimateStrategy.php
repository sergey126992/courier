<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Strategies;

use App\Domain\Identity\TransportType;
use App\Domain\Pricing\Contracts\DeliveryEstimateStrategy;
use App\Domain\Pricing\EstimateCalculator;
use App\Domain\Pricing\EstimateInput;
use App\Domain\Pricing\PriceQuote;
use App\Domain\Pricing\TransportParams;

/**
 * Расчёт для пешего курьера: без надбавок.
 */
final readonly class OnFootEstimateStrategy implements DeliveryEstimateStrategy
{
    public function __construct(
        private EstimateCalculator $calculator,
        private TransportParams $params,
    ) {}

    public function supports(EstimateInput $input): bool
    {
        return $input->transport() === TransportType::OnFoot;
    }

    public function estimate(EstimateInput $input): PriceQuote
    {
        return $this->calculator->quote($input, $this->params);
    }
}
