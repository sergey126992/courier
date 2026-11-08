<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Лента геоточек назначения (раздел 7 ЧТЗ, FR-TRK-101…108).
 *
 * Таблица только для добавления: нет updated_at, изменение и удаление записи
 * запрещены моделью. Таблица растёт линейно, поэтому время точки покрыто
 * BRIN-индексом, а лента назначения — обычным btree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_points', function (Blueprint $table) {
            $table->id();
            // Удаление назначения с точками запрещено; техническая очистка тестовых
            // данных выполняется отдельно (раздел 7 п. 10).
            $table->foreignId('assignment_id')->constrained('assignments')->restrictOnDelete();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->timestampTz('recorded_at');
            $table->string('source', 16);
            // Времени изменения у точки нет: запись создаётся один раз.
            $table->timestamp('created_at')->useCurrent();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE tracking_points ADD CONSTRAINT tracking_points_latitude_check
            CHECK (latitude >= -90 AND latitude <= 90)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE tracking_points ADD CONSTRAINT tracking_points_longitude_check
            CHECK (longitude >= -180 AND longitude <= 180)
            SQL);

        // Источник фиксируется полем; набор значений расширяется миграцией (FR-TRK-106).
        DB::statement(<<<'SQL'
            ALTER TABLE tracking_points ADD CONSTRAINT tracking_points_source_check
            CHECK (source IN ('courier'))
            SQL);

        // Время записи точек растёт вместе с таблицей: BRIN дешевле btree.
        DB::statement('CREATE INDEX tracking_points_recorded_at_brin ON tracking_points USING brin (recorded_at)');

        // Лента назначения с устойчивым порядком по времени и идентификатору.
        DB::statement('CREATE INDEX tracking_points_assignment_recorded_index ON tracking_points (assignment_id, recorded_at DESC, id DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_points');
    }
};
