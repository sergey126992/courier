<?php

declare(strict_types=1);

use App\Domain\Reporting\Exceptions\ReportCurrencyMismatch;
use App\Domain\Reporting\OrderReport;
use App\Domain\Reporting\ReportMetrics;
use App\Domain\Reporting\ReportPeriod;
use App\Domain\Reporting\TransportBreakdown;

/**
 * Период для тестов отчёта: ноябрь 2026 года.
 */
function reportingOrderPeriod(): ReportPeriod
{
    return new ReportPeriod(
        new DateTimeImmutable('2026-11-01 00:00:00'),
        new DateTimeImmutable('2026-12-01 00:00:00'),
        366 * 86400,
    );
}

it('собирает отчёт из агрегатов с одной валютой', function (): void {
    $metrics = new ReportMetrics(ordersDelivered: 10, activeCouriers: 4, shiftSeconds: 7200);
    $report = OrderReport::fromAggregates(
        reportingOrderPeriod(),
        'RUB',
        $metrics,
        TransportBreakdown::fromRows([]),
        ['RUB', 'RUB'],
    );

    expect($report->currency)->toBe('RUB')
        ->and($report->totals)->toBe($metrics)
        ->and($report->activeCouriers())->toBe(4)
        ->and($report->deliveriesPerCourier())->toBe(2.5)
        ->and($report->shiftHours())->toBe(2.0)
        ->and($report->period->days())->toBe(30);
});

it('не строит отчёт при чужой валюте в периоде', function (): void {
    expect(static fn (): OrderReport => OrderReport::fromAggregates(
        reportingOrderPeriod(),
        'RUB',
        new ReportMetrics,
        TransportBreakdown::fromRows([]),
        ['RUB', 'USD'],
    ))->toThrow(ReportCurrencyMismatch::class);
});
