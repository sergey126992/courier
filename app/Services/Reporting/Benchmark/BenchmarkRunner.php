<?php

declare(strict_types=1);

namespace App\Services\Reporting\Benchmark;

use App\Domain\Identity\TransportType;
use App\Domain\Reporting\ReportPeriod;
use App\Services\Reporting\CourierLoadQuery;
use App\Services\Reporting\OrderReportQuery;
use App\Services\Reporting\OrderReportService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/**
 * Замер отчёта по подготовленному набору (FR-RPT-163…168).
 *
 * Сервис прогревает отчётный сервис, повторяет его заданное число раз,
 * считает p50/p95 по методу ближайшего ранга и фиксирует число SQL. По запросу
 * снимает `EXPLAIN (ANALYZE, BUFFERS)` по тем же текстам, что выполняет
 * приложение, для отчёта, загрузки курьеров, очереди диспетчера и кабинета
 * отправителя (FR-RPT-165). Временные кандидаты индексов создаются и снимаются
 * здесь же и имеют префикс `bench_`, чтобы не пересечься с рабочими индексами
 * миграции.
 */
final class BenchmarkRunner
{
    /**
     * Валюта отчёта в замере.
     */
    private const CURRENCY = 'RUB';

    /**
     * Кандидаты индексов из раздела 7 ЧТЗ; временные имена с префиксом bench_,
     * в миграцию попадают только подтверждённые замером.
     *
     * @var array<string, string>
     */
    private const CANDIDATE_INDEXES = [
        'bench_delivery_orders_requested_brin' => 'CREATE INDEX bench_delivery_orders_requested_brin ON delivery_orders USING brin (requested_at)',
        'bench_assignments_delivered_partial' => "CREATE INDEX bench_assignments_delivered_partial ON assignments (delivered_at) WHERE status = 'delivered'",
    ];

    public function __construct(private readonly BenchmarkDataset $dataset) {}

    /**
     * Создаёт временные кандидаты индексов и обновляет статистику.
     *
     * @return list<string> имена созданных индексов
     */
    public function createIndexes(): array
    {
        $created = [];

        foreach (self::CANDIDATE_INDEXES as $name => $statement) {
            DB::statement('DROP INDEX IF EXISTS '.$name);
            DB::statement($statement);
            $created[] = $name;
        }

        DB::statement('ANALYZE delivery_orders');
        DB::statement('ANALYZE assignments');
        DB::statement('ANALYZE courier_shifts');

        return $created;
    }

    /**
     * @param list<string> $indexes
     */
    public function dropIndexes(array $indexes): void
    {
        foreach ($indexes as $name) {
            DB::statement('DROP INDEX IF EXISTS '.$name);
        }
    }

    /**
     * Замер отчётного сервиса и сбор результата.
     *
     * @param list<string> $indexes имена созданных кандидатов индексов
     * @return array<string, mixed> результат в форме JSON-вывода команды
     */
    public function run(BenchmarkOptions $options, array $indexes, bool $explain): array
    {
        // JIT PostgreSQL включается на границе стоимости плана: время компиляции
        // (сотни миллисекунд) попадает в замер и делает его нестабильным.
        // Замер снимается без JIT, чтобы сравнивать планы и индексы, а не LLVM;
        // это фиксируется в выводе (FR-RPT-167).
        DB::statement('SET jit = off');

        $service = app(OrderReportService::class);
        $period = new ReportPeriod(
            $options->from->toDateTimeImmutable(),
            $options->to->toDateTimeImmutable(),
            max(1, (int) config('reporting.max_period_days')) * 86400,
        );

        // Прогрев: планы и кэши страниц не должен оплачивать первый замер.
        $service->build($period, self::CURRENCY);

        $sqlCount = $this->countStatements(static function () use ($service, $period): void {
            $service->build($period, self::CURRENCY);
        });

        $samples = [];

        for ($run = 0; $run < $options->runs; $run++) {
            $startedAt = hrtime(true);
            $service->build($period, self::CURRENCY);
            $samples[] = (hrtime(true) - $startedAt) / 1_000_000;
        }

        $payload = [
            'status' => 'ok',
            'environment' => (string) app()->environment(),
            'postgres_version' => $this->postgresVersion(),
            'seed' => $options->seed,
            'dataset' => $this->dataset->stats() + [
                'period' => [
                    'from' => $period->from()->format('Y-m-d\TH:i:sP'),
                    'to' => $period->to()->format('Y-m-d\TH:i:sP'),
                    'days' => BenchmarkOptions::PERIOD_DAYS,
                ],
            ],
            'runs' => $options->runs,
            'with_indexes' => $indexes !== [],
            'indexes' => $indexes,
            'jit' => false,
            'metrics_ms' => $this->metrics($samples),
            'sql_queries' => $sqlCount,
        ];

        if ($explain) {
            $payload['plans'] = $this->plans($period);
        }

        return $payload;
    }

    /**
     * Число SELECT-запросов отчёта: служебные команды транзакций не считаются.
     *
     * @param callable(): void $operation
     */
    private function countStatements(callable $operation): int
    {
        $collector = new class
        {
            public int $selects = 0;

            public bool $enabled = false;
        };

        DB::listen(static function (QueryExecuted $query) use ($collector): void {
            if (! $collector->enabled) {
                return;
            }

            // Отчётные запросы начинаются с WITH, поэтому CTE считается SELECT-ом.
            $statement = strtolower(ltrim($query->sql));

            if (str_starts_with($statement, 'select') || str_starts_with($statement, 'with')) {
                $collector->selects++;
            }
        });

        $collector->enabled = true;
        $operation();
        $collector->enabled = false;

        return $collector->selects;
    }

    /**
     * Планы выполнения (FR-RPT-165): отчёт, загрузка курьеров, очередь, кабинет.
     *
     * @return array<string, list<string>>
     */
    private function plans(ReportPeriod $period): array
    {
        $report = app(OrderReportQuery::class);
        $load = app(CourierLoadQuery::class);

        $senderId = DB::selectOne(
            'SELECT id FROM users WHERE email = CAST(? AS text)',
            [BenchmarkDataset::SENDER_EMAIL],
        );
        $sender = is_object($senderId) ? (int) ((array) $senderId)['id'] : 0;

        return [
            'report' => $this->explain(
                $report->sql(),
                $report->bindings($period, TransportType::cases()),
            ),
            'courier_load' => $this->explain($load->sql(), $load->bindings($period)),
            'dispatcher_queue' => $this->explain(
                'SELECT * FROM delivery_orders WHERE status = ? ORDER BY created_at DESC, id DESC LIMIT 50',
                ['created'],
            ),
            'sender_orders' => $this->explain(
                'SELECT * FROM delivery_orders WHERE sender_id = ? ORDER BY created_at DESC, id DESC LIMIT 20',
                [$sender],
            ),
        ];
    }

    /**
     * EXPLAIN (ANALYZE, BUFFERS) по тому же тексту, что выполняет приложение.
     *
     * @param list<int|string> $bindings
     * @return list<string>
     */
    private function explain(string $sql, array $bindings): array
    {
        /** @var list<object> $rows */
        $rows = DB::select('EXPLAIN (ANALYZE, BUFFERS) '.$sql, $bindings);

        $lines = [];

        foreach ($rows as $row) {
            $data = (array) $row;
            $lines[] = (string) reset($data);
        }

        return $lines;
    }

    /**
     * p50/p95 по методу ближайшего ранга.
     *
     * @param list<float> $samples значения в миллисекундах
     * @return array{p50: float, p95: float, min: float, max: float, samples: int}
     */
    private function metrics(array $samples): array
    {
        sort($samples);

        return [
            'p50' => $this->percentile($samples, 0.50),
            'p95' => $this->percentile($samples, 0.95),
            'min' => $samples === [] ? 0.0 : round($samples[0], 3),
            'max' => $samples === [] ? 0.0 : round($samples[count($samples) - 1], 3),
            'samples' => count($samples),
        ];
    }

    /**
     * Значение процентиля по методу ближайшего ранга.
     *
     * @param list<float> $sorted отсортированные значения
     */
    private function percentile(array $sorted, float $percentile): float
    {
        if ($sorted === []) {
            return 0.0;
        }

        $index = (int) ceil($percentile * count($sorted)) - 1;
        $index = max(0, min(count($sorted) - 1, $index));

        return round($sorted[$index], 3);
    }

    /**
     * Версия PostgreSQL: она входит в артефакт замера (FR-RPT-167).
     */
    private function postgresVersion(): string
    {
        $row = DB::selectOne('SHOW server_version');
        $data = is_object($row) ? (array) $row : [];

        return (string) ($data['server_version'] ?? 'unknown');
    }
}
