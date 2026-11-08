<?php

declare(strict_types=1);

namespace App\Metrics;

/**
 * Одна серия текстового формата Prometheus (FR-OPS-501).
 *
 * Имя серии уже содержит суффикс гистограммы (`_bucket`, `_sum`, `_count`):
 * экспортёр выводит серии как есть, а `# HELP` и `# TYPE` относятся
 * к базовому имени метрики.
 */
final class PrometheusSample
{
    /**
     * @param array<string, string> $labels метки серии до фильтрации белым списком
     */
    public function __construct(
        public readonly string $name,
        public readonly array $labels,
        public readonly float $value,
    ) {}
}
