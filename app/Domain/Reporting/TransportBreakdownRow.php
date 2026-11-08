<?php

declare(strict_types=1);

namespace App\Domain\Reporting;

use App\Domain\Identity\TransportType;

/**
 * Строка разреза по типу транспорта (FR-RPT-131, FR-RPT-132).
 *
 * Доля выручки и место по выручке приходят из сводного SQL-запроса
 * (FR-RPT-134, FR-RPT-135): домен хранит готовые значения строки и не
 * пересчитывает их перебором, чтобы цифры базы и отчёта не расходились.
 */
final readonly class TransportBreakdownRow
{
    /**
     * @param float $sharePercent доля выручки типа в процентах с двумя знаками
     * @param int $rank место по выручке: равная выручка даёт равное место
     */
    public function __construct(
        public TransportType $transportType,
        public int $ordersCreated = 0,
        public int $ordersDelivered = 0,
        public int $revenue = 0,
        public float $sharePercent = 0.0,
        public int $rank = 1,
    ) {}
}
