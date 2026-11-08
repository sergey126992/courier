<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * JSON-вывод команды reporting:benchmark.
 *
 * @return array<string, mixed>
 */
function benchmarkPayload(): array
{
    /** @var array<string, mixed> $payload */
    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    return $payload;
}

/**
 * Число строк бенчмарка, оставшихся в базе по маркеру набора.
 */
function benchmarkRows(): int
{
    $row = (array) DB::selectOne(
        <<<'SQL'
            SELECT
                (SELECT count(*) FROM delivery_orders WHERE number LIKE 'BENCH-%')
                + (SELECT count(*) FROM users WHERE email LIKE '%@benchmark.local')
                + (SELECT count(*) FROM service_zones WHERE code = CAST('BENCH-ZONE' AS text)) AS total
            SQL,
    );

    return (int) ($row['total'] ?? 0);
}

it('наполняет малый набор, возвращает 0 и удаляет данные бенчмарка', function (): void {
    $exit = Artisan::call('reporting:benchmark', [
        '--orders' => 30,
        '--runs' => 3,
        '--json' => true,
    ]);

    $payload = benchmarkPayload();

    expect($exit)->toBe(0)
        ->and($payload['status'])->toBe('ok')
        ->and($payload['environment'])->toBe('testing')
        ->and($payload['seed'])->toBe(42)
        ->and($payload['runs'])->toBe(3)
        ->and($payload['postgres_version'])->not->toBe('')
        ->and($payload['dataset']['orders'])->toBe(30)
        ->and($payload['dataset']['couriers'])->toBe(200)
        ->and($payload['dataset']['assignments'])->toBeGreaterThan(0)
        ->and($payload['dataset']['shifts'])->toBeGreaterThan(0)
        ->and($payload['metrics_ms']['p50'])->toBeGreaterThanOrEqual(0.0)
        ->and($payload['metrics_ms']['p95'])->toBeGreaterThanOrEqual($payload['metrics_ms']['p50'])
        ->and($payload['metrics_ms']['samples'])->toBe(3)
        ->and($payload['sql_queries'])->toBe(2)
        ->and($payload['with_indexes'])->toBeFalse();

    // По умолчанию набор удаляется в finally: строк с маркером не остаётся.
    expect(benchmarkRows())->toBe(0);
});

it('принимает seed и повторяет набор без остатка данных', function (): void {
    $exit = Artisan::call('reporting:benchmark', [
        '--orders' => 30,
        '--runs' => 2,
        '--seed' => 7,
        '--json' => true,
    ]);

    $payload = benchmarkPayload();

    expect($exit)->toBe(0)
        ->and($payload['seed'])->toBe(7)
        ->and($payload['dataset']['orders'])->toBe(30)
        ->and(benchmarkRows())->toBe(0);
});

it('в production отказывает с ненулевым кодом и не меняет данные', function (): void {
    $before = benchmarkRows();

    app()->instance('env', 'production');

    try {
        $exit = Artisan::call('reporting:benchmark', [
            '--orders' => 30,
            '--runs' => 1,
            '--json' => true,
        ]);

        $payload = benchmarkPayload();

        expect($exit)->toBe(1)
            ->and($payload['status'])->toBe('error')
            ->and($payload['environment'])->toBe('production')
            ->and(benchmarkRows())->toBe($before)
            ->and($before)->toBe(0);
    } finally {
        app()->instance('env', 'testing');
    }
});
