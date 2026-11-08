<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Domain\Identity\TransportType;
use App\Domain\Pricing\Money;
use App\Domain\Pricing\PeakHourSchedule;
use App\Domain\Pricing\TransportParams;

/**
 * Превращает конфигурацию приложения в доменные параметры расчёта.
 *
 * Домен не знает о конфигурации: массив читается здесь, стратегии получают
 * уже готовый объект параметров.
 */
final class PricingConfig
{
    /**
     * Параметры расчёта для типа транспорта.
     */
    public function transportParams(TransportType $transport): TransportParams
    {
        $config = (array) config('pricing.transport.'.$transport->value);
        $currency = (string) config('pricing.currency');
        $parkingFee = $config['parking_fee_minor'] ?? null;
        $peakSpeed = $config['peak_speed_kmh'] ?? null;

        /** @var array<string, float|int> $speeds */
        $speeds = (array) ($config['speeds_kmh'] ?? []);

        return new TransportParams(
            maxWeightGrams: (int) $config['max_weight_grams'],
            maxRadiusKm: (float) $config['max_radius_km'],
            baseRate: Money::fromMinor((int) $config['base_rate_minor'], $currency),
            ratePerKm: Money::fromMinor((int) $config['rate_per_km_minor'], $currency),
            handlingMinutes: (int) $config['handling_minutes'],
            speedKmH: (float) $config['speed_kmh'],
            peakHours: $this->peakHours(),
            speedsByKind: array_map(static fn (float|int $speed): float => (float) $speed, $speeds),
            peakSpeedKmH: $peakSpeed !== null ? (float) $peakSpeed : null,
            peakSurchargePercent: (float) $config['peak_surcharge_percent'] / 100,
            parkingFee: $parkingFee !== null ? Money::fromMinor((int) $parkingFee, $currency) : null,
        );
    }

    /**
     * Расписание часов пик в часовом поясе приложения.
     */
    public function peakHours(): PeakHourSchedule
    {
        /** @var list<array{from: string, to: string}> $intervals */
        $intervals = (array) config('pricing.peak_hours.intervals', []);

        return new PeakHourSchedule($intervals, (string) config('app.timezone'));
    }
}
