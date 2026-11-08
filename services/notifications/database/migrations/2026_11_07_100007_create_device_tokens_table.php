<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Токены устройств для реального push (ЧТЗ 7.1, FR-NTF-525…530).
 *
 * Токен уникален глобально: повторная регистрация того же токена обновляет
 * строку, а не создаёт вторую (FR-NTF-525). Токен хранится целиком только
 * для отправки и нигде не выводится: для журнала есть `fingerprint`
 * (FR-NTF-529). Активные токены получателя ищутся частичным индексом
 * `(user_id) WHERE revoked_at IS NULL`, отбор по платформе — отдельным
 * индексом (FR-NTF-530). Все моменты времени — timestamptz (ЧТЗ 7.3 п. 2).
 *
 * Миграция обратима: down() удаляет таблицу вместе с индексами.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            // FCM-токен длинный, но с запасом помещается в 512 символов.
            $table->string('token', 512)->unique();
            // Отпечаток для логов: полный токен в журнал не попадает (FR-NTF-529).
            $table->string('fingerprint', 64);
            $table->string('platform', 32);
            $table->string('locale', 8)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->timestampTz('last_seen_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            // Отбор по платформе (FR-NTF-530).
            $table->index('platform');
        });

        // Активные токены получателя: частичный индекс, отозванные не мешают
        // выборке и очищаются по сроку хранения (FR-NTF-530, ЧТЗ 7.3 п. 6).
        DB::statement(
            'CREATE INDEX device_tokens_user_id_active_index
             ON device_tokens (user_id) WHERE revoked_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
