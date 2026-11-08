<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Последняя позиция по назначению (раздел 7 ЧТЗ, FR-TRK-111…116).
 *
 * Одна строка на назначение: значение обновляется вставкой с заменой по
 * уникальному ключу, поэтому число строк не зависит от числа геоточек.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_last_positions', function (Blueprint $table) {
            $table->id();
            // Удаление назначения уносит строку позиции: это техническая очистка.
            $table->foreignId('assignment_id')->unique()->constrained('assignments')->cascadeOnDelete();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->timestampTz('recorded_at');
            $table->timestamps();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE assignment_last_positions ADD CONSTRAINT assignment_last_positions_latitude_check
            CHECK (latitude >= -90 AND latitude <= 90)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE assignment_last_positions ADD CONSTRAINT assignment_last_positions_longitude_check
            CHECK (longitude >= -180 AND longitude <= 180)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_last_positions');
    }
};
