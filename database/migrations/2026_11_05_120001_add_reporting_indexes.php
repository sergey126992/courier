<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Индексы отчётности, польза которых подтверждена замерами (FR-RPT-164, FR-RPT-169).
 *
 * Каждый индекс добавлен только после `EXPLAIN (ANALYZE, BUFFERS)` «до/после»
 * на документированном наборе: сравнение планов, времени и buffers лежит в
 * артефактах `docs/Производительность`. Индексы «на всякий случай» сюда не попадают,
 * существующие индексы таблиц не заменяются и не удаляются.
 *
 * Миграция обратима: `down()` снимает ровно созданные здесь индексы.
 */
return new class extends Migration
{
    /**
     * Индексы отчёта и загрузки курьеров.
     *
     * @var list<array{name: string, statement: string}>
     */
    private const INDEXES = [
        // Сводный запрос отчёта дважды отбирает delivery_orders по requested_at
        // (созданные и валюты периода); BRIN дёшев по размеру и даёт диапазонный
        // отбор без полного обхода таблицы.
        [
            'name' => 'delivery_orders_requested_at_brin_index',
            'statement' => 'CREATE INDEX delivery_orders_requested_at_brin_index ON delivery_orders USING brin (requested_at)',
        ],
        // Вручения периода: в assignments нет индекса по delivered_at, частичный
        // btree по delivered содержит только нужные отчёту строки.
        [
            'name' => 'assignments_delivered_at_partial_index',
            'statement' => "CREATE INDEX assignments_delivered_at_partial_index ON assignments (delivered_at) WHERE status = 'delivered'",
        ],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $index) {
            DB::statement($index['statement']);
        }
    }

    public function down(): void
    {
        // Снимаются ровно созданные индексы; прочие индексы таблиц не трогаются.
        foreach (self::INDEXES as $index) {
            DB::statement('DROP INDEX IF EXISTS '.$index['name']);
        }
    }
};
