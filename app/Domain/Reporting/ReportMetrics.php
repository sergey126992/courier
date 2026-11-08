<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/**
 * Метрики отчёта по заказам, выручке и загрузке курьеров (FR-RPT-111…123).
 *
 * Конструктор принимает скалярные агрегаты, посчитанные базой. Количества и
 * суммы при отсутствии данных остаются нулями, средние и производные —
 * `null` при нулевом знаменателе (FR-RPT-123). Округление выполняет
 * {@see Rounding}.
 */
final readonly class ReportMetrics
{
    private const SECONDS_PER_HOUR = 3600;

    /**
     * @param int $etaMinutesTotal сумма ETA созданных заказов в минутах
     * @param int $shiftSeconds сумма пересечений смен с периодом в секундах
     */
    public function __construct(
        public int $ordersCreated = 0,
        public int $ordersCancelled = 0,
        public int $ordersDelivered = 0,
        public int $revenue = 0,
        public int $etaMinutesTotal = 0,
        public int $activeCouriers = 0,
        public int $shiftSeconds = 0,
    ) {}

    /**
     * Средний чек в минорных единицах; `null`, если доставок не было (FR-RPT-115).
     */
    public function averageCheck(): ?int
    {
        if ($this->ordersDelivered === 0) {
            return null;
        }

        return Rounding::toInteger($this->revenue / $this->ordersDelivered);
    }

    /**
     * Среднее ETA в минутах с одним знаком; `null`, если заказов не было (FR-RPT-116).
     */
    public function averageEtaMinutes(): ?float
    {
        if ($this->ordersCreated === 0) {
            return null;
        }

        return Rounding::toOneDecimal($this->etaMinutesTotal / $this->ordersCreated);
    }

    /**
     * Доставок на активного курьера с двумя знаками; `null`, если курьеров не было (FR-RPT-118).
     */
    public function deliveriesPerCourier(): ?float
    {
        if ($this->activeCouriers === 0) {
            return null;
        }

        return Rounding::toTwoDecimals($this->ordersDelivered / $this->activeCouriers);
    }

    /**
     * Отработанные часы смен с одним знаком (FR-RPT-119).
     */
    public function shiftHours(): float
    {
        return Rounding::toOneDecimal($this->shiftSeconds / self::SECONDS_PER_HOUR);
    }
}
