<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Плановая задержка отправки строки журнала (FR-NTF-436).
 *
 * `next_attempt_at` — момент, раньше которого строку не отправлять. Так
 * хранится `retry_after` лимита Telegram: значение приходит от провайдера
 * и не совпадает с backoff сервиса. Колонка не конфликтует с будущим
 * `deferred_until` тихих часов (пункт 9): смысл «не раньше момента»
 * общий, а причина и владелец значения разные.
 *
 * Индекс частичный: у большинства строк запрета нет, поэтому NULL-строки
 * в индекс не попадают. Миграция обратима: down() снимает индекс и колонку.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->timestampTz('next_attempt_at')->nullable();
        });

        DB::statement(
            'CREATE INDEX notifications_next_attempt_at_index'
            .' ON notifications (next_attempt_at) WHERE next_attempt_at IS NOT NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS notifications_next_attempt_at_index');

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('next_attempt_at');
        });
    }
};
