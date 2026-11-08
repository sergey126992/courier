<?php

declare(strict_types=1);

namespace App\Services\Reporting\Benchmark;

use Illuminate\Support\Carbon;

/**
 * Параметры запуска бенчмарка отчёта (FR-RPT-167).
 *
 * Отчётный период и окно генерации заказов фиксируются здесь, чтобы команда,
 * наполнение и замер работали с одними и теми же границами времени.
 */
final readonly class BenchmarkOptions
{
    /**
     * Длительность отчётного периода в днях: отчёт считается за последние
     * 30 дней, заказы при этом раскладываются по более широкому окну.
     */
    public const PERIOD_DAYS = 30;

    public function __construct(
        public int $orders,
        public int $runs,
        public int $seed,
        public Carbon $from,
        public Carbon $to,
    ) {}
}
