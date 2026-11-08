<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Domain\Identity\TransportType;
use App\Domain\Reporting\Exceptions\ReportCurrencyMismatch;
use App\Domain\Reporting\OrderReport;
use App\Domain\Reporting\ReportMetrics;
use App\Domain\Reporting\ReportPeriod;
use App\Domain\Reporting\TransportBreakdown;

/**
 * Сборка доменного отчёта из сводного агрегата базы (FR-RPT-109).
 *
 * Сервис вызывает ровно два запроса и переводит их значения в доменные
 * объекты: PHP не суммирует значения и не перебирает заказы (FR-RPT-122,
 * FR-RPT-136, FR-RPT-161). Второй запрос отдаёт секунды смен периода
 * (FR-RPT-119), число активных курьеров остаётся из сводного запроса
 * (FR-RPT-117), поэтому блоки `couriers` и `totals` согласованы. Чужая валюта
 * в агрегатах периода поднимает доменную ошибку (FR-RPT-154); перевод ошибки
 * в HTTP-ответ делает контроллер.
 */
final readonly class OrderReportService
{
    public function __construct(
        private OrderReportQuery $query,
        private CourierLoadQuery $loadQuery,
    ) {}

    /**
     * Отчёт за период в настроенной валюте.
     *
     * @throws ReportCurrencyMismatch в агрегатах периода встречена чужая валюта
     */
    public function build(ReportPeriod $period, string $currency): OrderReport
    {
        $aggregate = $this->query->fetch($period, TransportType::cases());

        // Второй и последний SQL отчёта: секунды смен периода (FR-RPT-119).
        $shiftSeconds = $this->loadQuery->fetch($period);

        return OrderReport::fromAggregates(
            $period,
            $currency,
            new ReportMetrics(
                ordersCreated: $aggregate->ordersCreated,
                ordersCancelled: $aggregate->ordersCancelled,
                ordersDelivered: $aggregate->ordersDelivered,
                revenue: $aggregate->revenue,
                etaMinutesTotal: $aggregate->etaMinutesTotal,
                activeCouriers: $aggregate->activeCouriers,
                shiftSeconds: $shiftSeconds,
            ),
            TransportBreakdown::fromRows($aggregate->rows),
            $aggregate->currencies,
        );
    }
}
