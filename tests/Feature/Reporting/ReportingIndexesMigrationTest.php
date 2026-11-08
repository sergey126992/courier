<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Индексы, которые создаёт миграция отчётности.
 *
 * @return list<string>
 */
function reportingIndexNames(): array
{
    return [
        'delivery_orders_requested_at_brin_index',
        'assignments_delivered_at_partial_index',
    ];
}

/**
 * Наличие индекса в схеме по pg_indexes.
 */
function reportingIndexExists(string $name): bool
{
    return DB::selectOne(
        'SELECT 1 AS present FROM pg_indexes WHERE schemaname = CAST(? AS text) AND indexname = CAST(? AS text)',
        ['public', $name],
    ) !== null;
}

it('создаёт индексы отчётности, откат удаляет ровно их и повторное применение проходит', function (): void {
    $indexes = reportingIndexNames();

    foreach ($indexes as $name) {
        expect(reportingIndexExists($name))->toBeTrue();
    }

    // Откатываем именно миграцию отчётности, а не «последнюю»: файлы миграций
    // добавляются со временем, и более новый файл не должен влиять на тест.
    // Берём батч самой миграции и ограничиваем откат её путём; `--step` здесь
    // не подходит, потому что он отбирает последнюю запись в истории миграций.
    $batch = (int) DB::table('migrations')
        ->where('migration', '2026_11_05_120001_add_reporting_indexes')
        ->value('batch');

    expect($batch)->toBeGreaterThan(0);

    $rollback = Artisan::call('migrate:rollback', [
        '--batch' => $batch,
        '--path' => 'database/migrations/2026_11_05_120001_add_reporting_indexes.php',
    ]);

    expect($rollback)->toBe(0);

    foreach ($indexes as $name) {
        expect(reportingIndexExists($name))->toBeFalse();
    }

    // Существующие индексы таблиц миграция не трогает.
    expect(reportingIndexExists('delivery_orders_status_created_index'))->toBeTrue()
        ->and(reportingIndexExists('assignments_courier_assigned_index'))->toBeTrue();

    $migrate = Artisan::call('migrate');

    expect($migrate)->toBe(0);

    foreach ($indexes as $name) {
        expect(reportingIndexExists($name))->toBeTrue();
    }
});
