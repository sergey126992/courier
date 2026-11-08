<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Одноразовые токены привязки Telegram (ЧТЗ 7.1, FR-NTF-411…421).
 *
 * Хранится только хеш значения (SHA-256): открытого токена в базе нет
 * (FR-NTF-413). Уникальный индекс `token_hash` не даёт двум строкам
 * описывать один токен; индекс `(user_id, created_at desc)` отдаёт
 * последний выпущенный токен пользователя, индекс `(expires_at)`
 * обслуживает очистку (ЧТЗ 7, п. 8). Моменты времени — timestamptz
 * (ЧТЗ 7, п. 5), поэтому updated_at у неизменяемой строки нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        // Последний выпущенный токен пользователя: обратный порядок времени
        // создания отдаёт его без сортировки всей таблицы.
        DB::statement(
            'CREATE INDEX telegram_link_tokens_user_created_desc_index
             ON telegram_link_tokens (user_id, created_at DESC)'
        );

        // Истёкшие и использованные токены чистятся по сроку хранения.
        DB::statement(
            'CREATE INDEX telegram_link_tokens_expires_at_index
             ON telegram_link_tokens (expires_at)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_link_tokens');
    }
};
