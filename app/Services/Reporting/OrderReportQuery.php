<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Domain\Identity\TransportType;
use App\Domain\Reporting\ReportPeriod;
use App\Domain\Reporting\TransportBreakdownRow;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Сводный агрегат отчёта по заказам одним SQL-запросом (FR-RPT-136, FR-RPT-161).
 *
 * Запрос отдаёт строки разреза по типам транспорта вместе с итогами, долями,
 * местами и перечнем встреченных валют. Доли и места считают оконные функции
 * по всем строкам сразу (FR-RPT-134, FR-RPT-135), поэтому PHP не перебирает
 * заказы и не суммирует значения поштучно (FR-RPT-122). Модели не
 * гидратируются: результат читается построчно (FR-RPT-171).
 *
 * Границы периода передаются строками с явным смещением: Laravel форматирует
 * DateTime без смещения, и границы «поехали» бы относительно сессии БД
 * (FR-RPT-147, FR-RPT-149). Список типов транспорта приходит из домена и
 * подаётся в `unnest`, поэтому перечня в тексте SQL нет (FR-RPT-137).
 */
final readonly class OrderReportQuery
{
    /**
     * Один запрос: параметры периода, список типов, вручения, созданные и
     * вручённые заказы, отмены, активные курьеры и валюты периода.
     */
    private const SQL = <<<'SQL'
        WITH params AS (
            SELECT CAST(? AS timestamptz) AS period_from,
                   CAST(? AS timestamptz) AS period_to
        ),
        types AS (
            SELECT type_value AS transport_type, sort_order
            FROM unnest(CAST(? AS text[])) WITH ORDINALITY AS t(type_value, sort_order)
        ),
        delivered_assignments AS (
            SELECT a.order_id, a.courier_id
            FROM assignments a
            CROSS JOIN params p
            WHERE a.status = 'delivered'
              AND a.delivered_at >= p.period_from
              AND a.delivered_at < p.period_to
        ),
        delivered_orders AS (
            SELECT DISTINCT o.id, o.transport_type, o.price_amount
            FROM delivery_orders o
            JOIN delivered_assignments d ON d.order_id = o.id
        ),
        created AS (
            SELECT o.transport_type,
                   COUNT(*) AS orders_created,
                   COALESCE(SUM(o.eta_minutes), 0) AS eta_minutes_total
            FROM delivery_orders o
            CROSS JOIN params p
            WHERE o.requested_at >= p.period_from
              AND o.requested_at < p.period_to
            GROUP BY o.transport_type
        ),
        delivered AS (
            SELECT transport_type,
                   COUNT(*) AS orders_delivered,
                   COALESCE(SUM(price_amount), 0) AS revenue
            FROM delivered_orders
            GROUP BY transport_type
        ),
        cancelled AS (
            SELECT COUNT(*) AS orders_cancelled
            FROM delivery_orders o
            CROSS JOIN params p
            WHERE o.cancelled_at IS NOT NULL
              AND o.cancelled_at >= p.period_from
              AND o.cancelled_at < p.period_to
        ),
        couriers AS (
            SELECT COUNT(DISTINCT courier_id) AS active_couriers
            FROM delivered_assignments
        ),
        currencies AS (
            SELECT DISTINCT o.price_currency
            FROM delivery_orders o
            CROSS JOIN params p
            WHERE (o.requested_at >= p.period_from AND o.requested_at < p.period_to)
               OR EXISTS (
                    SELECT 1
                    FROM delivered_assignments d
                    WHERE d.order_id = o.id
               )
        )
        SELECT
            t.transport_type,
            COALESCE(c.orders_created, 0) AS orders_created,
            COALESCE(d.orders_delivered, 0) AS orders_delivered,
            COALESCE(d.revenue, 0) AS revenue,
            COALESCE(
                ROUND(COALESCE(d.revenue, 0) * 100.0 / NULLIF(SUM(COALESCE(d.revenue, 0)) OVER (), 0), 2),
                0
            ) AS share_percent,
            RANK() OVER (ORDER BY COALESCE(d.revenue, 0) DESC) AS "rank",
            SUM(COALESCE(c.orders_created, 0)) OVER () AS total_orders_created,
            SUM(COALESCE(c.eta_minutes_total, 0)) OVER () AS total_eta_minutes,
            SUM(COALESCE(d.orders_delivered, 0)) OVER () AS total_orders_delivered,
            SUM(COALESCE(d.revenue, 0)) OVER () AS total_revenue,
            (SELECT orders_cancelled FROM cancelled) AS total_orders_cancelled,
            (SELECT active_couriers FROM couriers) AS total_active_couriers,
            (SELECT COALESCE(json_agg(price_currency), '[]'::json) FROM currencies) AS currencies
        FROM types t
        LEFT JOIN created c ON c.transport_type = t.transport_type
        LEFT JOIN delivered d ON d.transport_type = t.transport_type
        ORDER BY t.sort_order
        SQL;

    /**
     * Агрегаты периода по переданному доменом списку типов транспорта.
     *
     * @param list<TransportType> $transportTypes типы в доменном порядке
     */
    public function fetch(ReportPeriod $period, array $transportTypes): OrderReportAggregate
    {
        /** @var list<object> $result */
        $result = DB::select($this->sql(), $this->bindings($period, $transportTypes));

        $rows = [];
        $aggregate = new OrderReportAggregate;

        foreach ($result as $index => $row) {
            $data = (array) $row;

            $rows[] = new TransportBreakdownRow(
                transportType: TransportType::from((string) $data['transport_type']),
                ordersCreated: (int) $data['orders_created'],
                ordersDelivered: (int) $data['orders_delivered'],
                revenue: (int) $data['revenue'],
                sharePercent: (float) $data['share_percent'],
                rank: (int) $data['rank'],
            );

            // Итоги повторяются в каждой строке оконными функциями: берём первые.
            if ($index === 0) {
                $aggregate = new OrderReportAggregate(
                    ordersCreated: (int) $data['total_orders_created'],
                    ordersCancelled: (int) $data['total_orders_cancelled'],
                    ordersDelivered: (int) $data['total_orders_delivered'],
                    revenue: (int) $data['total_revenue'],
                    etaMinutesTotal: (int) $data['total_eta_minutes'],
                    activeCouriers: (int) $data['total_active_couriers'],
                    currencies: $this->currencies($data['currencies'] ?? null),
                );
            }
        }

        return new OrderReportAggregate(
            ordersCreated: $aggregate->ordersCreated,
            ordersCancelled: $aggregate->ordersCancelled,
            ordersDelivered: $aggregate->ordersDelivered,
            revenue: $aggregate->revenue,
            etaMinutesTotal: $aggregate->etaMinutesTotal,
            activeCouriers: $aggregate->activeCouriers,
            rows: $rows,
            currencies: $aggregate->currencies,
        );
    }

    /**
     * Текст запроса отчёта — тот же, что выполняет fetch (FR-RPT-166).
     */
    public function sql(): string
    {
        return self::SQL;
    }

    /**
     * Биндинги запроса отчёта в порядке плейсхолдеров (FR-RPT-166).
     *
     * @param list<TransportType> $transportTypes типы в доменном порядке
     * @return array{0: string, 1: string, 2: string}
     */
    public function bindings(ReportPeriod $period, array $transportTypes): array
    {
        return [
            $this->boundary($period->from()),
            $this->boundary($period->to()),
            $this->arrayLiteral($transportTypes),
        ];
    }

    /**
     * Граница периода строкой с явным смещением (FR-RPT-147).
     */
    private function boundary(DateTimeInterface $moment): string
    {
        return $moment->format('Y-m-d H:i:sP');
    }

    /**
     * Литерал массива PostgreSQL из значений доменного списка (FR-RPT-137).
     *
     * @param list<TransportType> $transportTypes
     */
    private function arrayLiteral(array $transportTypes): string
    {
        $values = array_map(
            static fn (TransportType $type): string => '"'.addcslashes($type->value, '"\\').'"',
            $transportTypes,
        );

        return '{'.implode(',', $values).'}';
    }

    /**
     * Перечень валют из JSON-агрегата запроса (FR-RPT-156).
     *
     * @return list<string>
     */
    private function currencies(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $value): string => (string) $value,
            $decoded,
        ));
    }
}
