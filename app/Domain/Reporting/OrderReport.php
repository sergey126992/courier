<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Reporting\Exceptions\ReportCurrencyMismatch;

/**
 * Собранный отчёт по заказам: период, валюта, итоги, разрез и загрузка курьеров (ЧТЗ 8.3).
 *
 * Отчёт собирается только из одной валюты: если среди заказов периода
 * встретилась другая, отчёт не строится (FR-RPT-153, FR-RPT-154). Блок
 * курьеров намеренно повторяет значения итогов через делегирование, чтобы
 * блоки не могли разойтись.
 */
final readonly class OrderReport
{
    private function __construct(
        public ReportPeriod $period,
        public string $currency,
        public ReportMetrics $totals,
        public TransportBreakdown $byTransport,
    ) {}

    /**
     * @param list<string> $foundCurrencies валюты заказов, попавших в агрегаты периода
     *
     * @throws ReportCurrencyMismatch найдена валюта, отличная от валюты отчёта
     */
    public static function fromAggregates(
        ReportPeriod $period,
        string $currency,
        ReportMetrics $totals,
        TransportBreakdown $byTransport,
        array $foundCurrencies = [],
    ): self {
        foreach ($foundCurrencies as $foundCurrency) {
            if ($foundCurrency !== $currency) {
                throw ReportCurrencyMismatch::detected();
            }
        }

        return new self($period, $currency, $totals, $byTransport);
    }

    /**
     * Курьеры, у которых были вручения в периоде (FR-RPT-117).
     */
    public function activeCouriers(): int
    {
        return $this->totals->activeCouriers;
    }

    /**
     * Доставок на активного курьера (FR-RPT-118).
     */
    public function deliveriesPerCourier(): ?float
    {
        return $this->totals->deliveriesPerCourier();
    }

    /**
     * Отработанные часы смен (FR-RPT-119).
     */
    public function shiftHours(): float
    {
        return $this->totals->shiftHours();
    }
}
