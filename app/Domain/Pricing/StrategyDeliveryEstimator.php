<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Pricing\Contracts\DeliveryEstimateStrategy;
use App\Domain\Pricing\Exceptions\ParcelOutsideDeliveryRadius;
use App\Domain\Pricing\Exceptions\ParcelTooHeavyForTransport;
use App\Domain\Pricing\Exceptions\UnsupportedTransport;

/**
 * Выбор стратегии расчёта: результат первой стратегии, поддержавшей входные данные.
 *
 * Набор стратегий передаётся снаружи, поэтому добавление нового транспорта
 * не требует правки этого класса и вызывающего кода.
 */
final readonly class StrategyDeliveryEstimator
{
    /**
     * @param iterable<DeliveryEstimateStrategy> $strategies
     */
    public function __construct(
        private iterable $strategies,
    ) {}

    /**
     * @throws ParcelTooHeavyForTransport выбранная стратегия отклонила вес посылки
     * @throws ParcelOutsideDeliveryRadius выбранная стратегия отклонила расстояние
     * @throws UnsupportedTransport ни одна стратегия не поддержала входные данные
     */
    public function estimate(EstimateInput $input): PriceQuote
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($input)) {
                return $strategy->estimate($input);
            }
        }

        throw UnsupportedTransport::forTransport($input->transport());
    }
}
