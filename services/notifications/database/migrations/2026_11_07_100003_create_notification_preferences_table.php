<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Предпочтения получателя по типу события (ЧТЗ 7.1, FR-NTF-451…462).
 *
 * Одна строка на пару «пользователь — тип события»: уникальный ключ не даёт
 * дублю появиться при повторной записи, отдельный индекс по `user_id`
 * отдаёт набор пользователя. Структурные CHECK держат только форму jsonb:
 * состав каналов, локаль, окно и критичность проверяет приложение
 * (ЧТЗ 12.1), поэтому литеральных перечней в схеме нет. Моменты времени —
 * timestamptz (ЧТЗ 7, п. 5). Миграция обратима.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_preferences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('event_type', 64);
            $table->jsonb('channels');
            $table->jsonb('quiet_hours')->nullable();
            $table->string('locale', 8);
            $table->boolean('critical_override')->default(false);
            $table->timestampsTz();

            $table->unique(['user_id', 'event_type']);
            $table->index('user_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE notification_preferences
            ADD CONSTRAINT notification_preferences_channels_array_check
            CHECK (jsonb_typeof(channels) = 'array')
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE notification_preferences
            ADD CONSTRAINT notification_preferences_quiet_hours_object_check
            CHECK (quiet_hours IS NULL OR jsonb_typeof(quiet_hours) = 'object')
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_preferences');
    }
};
