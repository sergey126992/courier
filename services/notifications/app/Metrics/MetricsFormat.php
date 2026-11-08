<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * Запись чисел в формате Prometheus text 0.0.4 (FR-OPS-501).
 *
 * Целые значения выводятся без дробной части, дробные — без хвостовых
 * нулей: `1`, `0.5`, `2.25`. Бесконечность записывается `+Inf`/`-Inf`.
 * Единая точка форматирования нужна и экспортёру, и счётчикам гистограммы:
 * ключ границы (`le`) и значение в тексте обязаны совпадать.
 */
final class MetricsFormat
{
    /**
     * Число в записи формата Prometheus.
     */
    public static function float(float $value): string
    {
        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }

        if ($value === floor($value) && abs($value) < 1.0e15) {
            return sprintf('%.0F', $value);
        }

        $text = rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $text === '' || $text === '-' ? '0' : $text;
    }
}
