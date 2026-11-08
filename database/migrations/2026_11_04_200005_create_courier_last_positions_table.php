<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Последняя позиция курьера (раздел 7 ЧТЗ, FR-TRK-111…116).
 *
 * Обновляется геоточками назначения и heartbeat; хранится отдельно от
 * последней позиции назначения, чтобы позиция курьера была доступна и вне
 * активного назначения.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courier_last_positions', function (Blueprint $table) {
            $table->id();
            // Удаление пользователя уносит строку позиции: это техническая очистка.
            $table->foreignId('courier_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->timestampTz('recorded_at');
            $table->timestamps();

            // Отсев устаревших позиций при подборе кандидатов (раздел 7 ЧТЗ).
            $table->index('recorded_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE courier_last_positions ADD CONSTRAINT courier_last_positions_latitude_check
            CHECK (latitude >= -90 AND latitude <= 90)
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE courier_last_positions ADD CONSTRAINT courier_last_positions_longitude_check
            CHECK (longitude >= -180 AND longitude <= 180)
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_last_positions');
    }
};
