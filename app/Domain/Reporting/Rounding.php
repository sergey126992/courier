<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

/**
 * Единые правила округления метрик отчёта (FR-RPT-115, FR-RPT-116, FR-RPT-118, FR-RPT-134).
 *
 * Округление — половина вверх: значения метрик не должны расходиться между
 * доменом и SQL, поэтому правило живёт в одном месте.
 */
final class Rounding
{
    private function __construct() {}

    /**
     * Округление до целого: денежные суммы в минорных единицах.
     */
    public static function toInteger(float $value): int
    {
        return (int) round($value, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * Округление до одного знака: среднее ETA и часы смен.
     */
    public static function toOneDecimal(float $value): float
    {
        return round($value, 1, PHP_ROUND_HALF_UP);
    }

    /**
     * Округление до двух знаков: доля выручки и доставки на курьера.
     */
    public static function toTwoDecimals(float $value): float
    {
        return round($value, 2, PHP_ROUND_HALF_UP);
    }
}
