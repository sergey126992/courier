<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Отложенная отправка в тихие часы (FR-NTF-493…497).
 *
 * `deferred_until` — ближайшее окончание окна тихих часов в UTC, вычисленное
 * при создании строки. Значение не пересчитывается при изменении настроек:
 * уже принятое решение об отложенности остаётся неизменным (FR-NTF-497).
 * Смысл «не раньше момента» общий с `next_attempt_at`, но причина разная:
 * там плановая задержка провайдера, здесь окно получателя.
 *
 * Индекс частичный: у большинства строк значения нет, NULL-строки в индекс
 * не попадают. Миграция обратима: down() снимает индекс и колонку.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->timestampTz('deferred_until')->nullable();
        });

        DB::statement(
            'CREATE INDEX notifications_deferred_until_index'
            .' ON notifications (deferred_until) WHERE deferred_until IS NOT NULL',
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS notifications_deferred_until_index');

        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('deferred_until');
        });
    }
};
