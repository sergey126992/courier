<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Domain\Reporting\TransportBreakdownRow;

/**
 * Результат сводного запроса отчёта: итоги, строки разреза и валюты периода.
 *
 * Объект передаёт посчитанные базой значения без пересчёта в PHP
 * (FR-RPT-122, FR-RPT-136). Перечень валют нужен домену для проверки
 * смешивания валют (FR-RPT-153, FR-RPT-156).
 */
final readonly class OrderReportAggregate
{
    /**
     * @param int $etaMinutesTotal сумма ETA созданных за период заказов
     * @param list<TransportBreakdownRow> $rows строки разреза в доменном порядке
     * @param list<string> $currencies валюты заказов, попавших в агрегаты периода
     */
    public function __construct(
        public int $ordersCreated = 0,
        public int $ordersCancelled = 0,
        public int $ordersDelivered = 0,
        public int $revenue = 0,
        public int $etaMinutesTotal = 0,
        public int $activeCouriers = 0,
        public array $rows = [],
        public array $currencies = [],
    ) {}
}
