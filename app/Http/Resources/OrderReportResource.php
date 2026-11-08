<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Reporting\OrderReport;
use App\Domain\Reporting\TransportBreakdownRow;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * Ответ отчёта по заказам в структуре ЧТЗ 8.3.
 *
 * Форматирование сосредоточено в ресурсе: контроллер только получает отчёт из
 * сервиса. Период отдаётся с явным смещением (FR-RPT-148), валюта — один раз
 * в корне (FR-RPT-152), разрез — ровно три строки в доменном порядке
 * (FR-RPT-133), блок курьеров повторяет значения итогов (FR-RPT-117…119).
 */
final class OrderReportResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OrderReport $report */
        $report = $this->resource;

        $period = $report->period;
        $totals = $report->totals;

        return [
            'period' => [
                // Смещение указывается явно, чтобы клиент видел трактовку границ.
                'from' => $period->from()->format(DateTimeInterface::ATOM),
                'to' => $period->to()->format(DateTimeInterface::ATOM),
                'days' => $period->days(),
            ],
            'currency' => $report->currency,
            'totals' => [
                'orders_created' => $totals->ordersCreated,
                'orders_cancelled' => $totals->ordersCancelled,
                'orders_delivered' => $totals->ordersDelivered,
                'revenue' => $totals->revenue,
                'average_check' => $totals->averageCheck(),
                'average_eta_minutes' => $totals->averageEtaMinutes(),
                'active_couriers' => $totals->activeCouriers,
                'deliveries_per_courier' => $totals->deliveriesPerCourier(),
            ],
            'by_transport' => array_map(
                static fn (TransportBreakdownRow $row): array => [
                    'transport_type' => $row->transportType->value,
                    'orders_created' => $row->ordersCreated,
                    'orders_delivered' => $row->ordersDelivered,
                    'revenue' => $row->revenue,
                    'share_percent' => $row->sharePercent,
                    'rank' => $row->rank,
                ],
                $report->byTransport->rows(),
            ),
            // Блок курьеров повторяет значения итогов: расхождение было бы дефектом.
            'couriers' => [
                'active_couriers' => $report->activeCouriers(),
                'deliveries_per_courier' => $report->deliveriesPerCourier(),
                'shift_hours' => $report->shiftHours(),
            ],
        ];
    }
}
