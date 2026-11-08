<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Привязки каналов, готовые к Telegram M12 (ЧТЗ 7.1, FR-NTF-231…236).
 *
 * Активная привязка одна на пару «пользователь — канал»: частичный уникальный
 * индекс по строкам без revoked_at. Адрес — персональные данные: в логах и
 * ответах API он не выводится (FR-OPS-202, FR-OPS-210).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_bindings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('channel', 16);
            $table->string('address');
            $table->timestampTz('verified_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX channel_bindings_active_unique
            ON channel_bindings (user_id, channel)
            WHERE revoked_at IS NULL
            SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE channel_bindings ADD CONSTRAINT channel_bindings_channel_check
            CHECK (channel IN ('email', 'push', 'telegram'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_bindings');
    }
};
