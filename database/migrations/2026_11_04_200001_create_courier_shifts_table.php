<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Смены курьеров (раздел 7 ЧТЗ, FR-DSP-101…106).
 *
 * Пересечение смен одного курьера запрещено ограничением базы: интервал
 * «начало — окончание» (для открытой смены — до бесконечности) не может
 * пересекаться с другим интервалом того же курьера. Для сравнения
 * идентификаторов в GiST-ограничении включается расширение btree_gist.
 */
return new class extends Migration
{
    public function up(): void
    {
        // GiST-ограничение сравнивает courier_id оператором «равно».
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        Schema::create('courier_shifts', function (Blueprint $table) {
            $table->id();
            // Удаление пользователя со сменами запрещено: история смен не теряется.
            $table->foreignId('courier_id')->constrained('users')->restrictOnDelete();
            // Смена без зоны подходит для заказов любой зоны (FR-DSP-114).
            $table->foreignId('zone_id')->nullable()->constrained('service_zones')->nullOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->timestamps();
        });

        // Открытая смена ограничена сверху бесконечностью: она пересекается
        // с любой следующей сменой курьера.
        DB::statement(<<<'SQL'
            ALTER TABLE courier_shifts ADD CONSTRAINT courier_shifts_period_excl
            EXCLUDE USING gist (
                courier_id WITH =,
                tstzrange(started_at, coalesce(ended_at, 'infinity')) WITH &&
            )
            SQL);

        // Выборка смен курьера в обратном порядке: последняя сверху.
        DB::statement('CREATE INDEX courier_shifts_courier_started_index ON courier_shifts (courier_id, started_at DESC)');
    }

    public function down(): void
    {
        // Ограничение и индексы удаляются вместе с таблицей.
        Schema::dropIfExists('courier_shifts');

        // Расширение создавалось этой миграцией и больше не нужно.
        DB::statement('DROP EXTENSION IF EXISTS btree_gist');
    }
};
