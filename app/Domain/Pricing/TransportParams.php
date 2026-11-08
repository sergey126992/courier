<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Identity\LightVehicleKind;
use DateTimeImmutable;

/**
 * Параметры расчёта для одного типа транспорта.
 *
 * Значения приходят снаружи (из конфигурации приложения) и передаются стратегии
 * при создании — домен не читает конфигурацию сам.
 */
final readonly class TransportParams
{
    /**
     * @param array<string, float> $speedsByKind скорость по виду лёгкого транспорта, ключ — значение LightVehicleKind
     * @param float $peakSurchargePercent надбавка за час пик долей единицы (0.15 = 15%)
     */
    public function __construct(
        private int $maxWeightGrams,
        private float $maxRadiusKm,
        private Money $baseRate,
        private Money $ratePerKm,
        private int $handlingMinutes,
        private float $speedKmH,
        private PeakHourSchedule $peakHours,
        private array $speedsByKind = [],
        private ?float $peakSpeedKmH = null,
        private float $peakSurchargePercent = 0.0,
        private ?Money $parkingFee = null,
    ) {}

    /**
     * Предельный вес посылки в граммах.
     */
    public function maxWeightGrams(): int
    {
        return $this->maxWeightGrams;
    }

    /**
     * Радиус обслуживания в километрах.
     */
    public function maxRadiusKm(): float
    {
        return $this->maxRadiusKm;
    }

    /**
     * Базовая ставка.
     */
    public function baseRate(): Money
    {
        return $this->baseRate;
    }

    /**
     * Ставка за километр.
     */
    public function ratePerKm(): Money
    {
        return $this->ratePerKm;
    }

    /**
     * Время на погрузку и выгрузку в минутах.
     */
    public function handlingMinutes(): int
    {
        return $this->handlingMinutes;
    }

    /**
     * Скорость движения с учётом вида транспорта и часа пик.
     */
    public function speedKmH(?LightVehicleKind $kind, bool $isPeakHour): float
    {
        if ($isPeakHour && $this->peakSpeedKmH !== null) {
            return $this->peakSpeedKmH;
        }

        if ($kind !== null && isset($this->speedsByKind[$kind->value])) {
            return $this->speedsByKind[$kind->value];
        }

        return $this->speedKmH;
    }

    /**
     * Попадает ли момент заявки в час пик.
     */
    public function isPeakHour(DateTimeImmutable $at): bool
    {
        return $this->peakHours->isPeakHour($at);
    }

    /**
     * Надбавка за час пик долей единицы: 0.15 соответствует 15%.
     */
    public function peakSurchargePercent(): float
    {
        return $this->peakSurchargePercent;
    }

    /**
     * Надбавка за платную парковку, если она предусмотрена транспортом.
     */
    public function parkingFee(): ?Money
    {
        return $this->parkingFee;
    }
}
