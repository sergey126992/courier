<?php

declare(strict_types=1);

use App\Domain\Identity\TransportType;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use App\Services\Reporting\OrderReportService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Считает SELECT/WITH-операторы, выполненные внутри переданного участка кода.
 *
 * Сводные запросы отчёта начинаются с `WITH` (CTE), поэтому учитываются оба
 * начала: `select` и `with` в любом регистре, с ведущими пробелами и переводами
 * строк. Служебные команды (BEGIN, COMMIT, SAVEPOINT, RELEASE, ROLLBACK, SET,
 * SHOW) не считаются: они не начинаются с `select`/`with`.
 *
 * @param callable(): void $operation
 */
function reportingCountSelectAndWithStatements(callable $operation): int
{
    // Счётчик в объекте: состояние читается из замыкания без ссылок.
    $collector = new class
    {
        public int $statements = 0;

        public bool $enabled = false;
    };

    DB::listen(static function (QueryExecuted $query) use ($collector): void {
        if (! $collector->enabled) {
            return;
        }

        $sql = strtolower(ltrim($query->sql));

        if (! str_starts_with($sql, 'select') && ! str_starts_with($sql, 'with')) {
            return;
        }

        $collector->statements++;
    });

    $collector->enabled = true;
    $operation();
    $collector->enabled = false;

    return $collector->statements;
}

it('выполняет ровно два SELECT/WITH-оператора независимо от объёма данных', function (): void {
    $service = app(OrderReportService::class);
    $period = reportingExplicitPeriod();

    reportingDataset();

    $small = reportingCountSelectAndWithStatements(static function () use ($service, $period): void {
        $service->build($period, 'RUB');
    });

    // Больший набор: число запросов не должно измениться.
    $courier = User::factory()->courier()->create();

    for ($i = 0; $i < 25; $i++) {
        $order = reportingOrder(TransportType::Light, 1000 + $i, 10, '2026-11-02 10:00:00');
        reportingDelivery($order, $courier, '2026-11-02 12:00:00');
    }

    $large = reportingCountSelectAndWithStatements(static function () use ($service, $period): void {
        $service->build($period, 'RUB');
    });

    // Ровно два оператора: сводный CTE-запрос и запрос смен (FR-RPT-161).
    expect($small)->toBe(2)
        ->and($large)->toBe(2);
});

it('не гидратирует модели заказов и назначений', function (): void {
    reportingDataset();

    $retrieved = 0;

    $listener = static function () use (&$retrieved): void {
        $retrieved++;
    };

    Event::listen('eloquent.retrieved: '.DeliveryOrder::class, $listener);
    Event::listen('eloquent.retrieved: '.Assignment::class, $listener);

    $report = app(OrderReportService::class)->build(reportingExplicitPeriod(), 'RUB');

    expect($report->totals->ordersDelivered)->toBe(4)
        ->and($report->byTransport->rows())->toHaveCount(3)
        ->and($retrieved)->toBe(0);
});
