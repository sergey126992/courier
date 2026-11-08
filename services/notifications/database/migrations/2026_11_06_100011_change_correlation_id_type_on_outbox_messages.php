<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * correlation_id обратного события — идентификатор запроса, а не UUID (FR-OPS-201).
 *
 * Монолит переносит в событие request_id, принятый по шаблону 8–128 символов
 * (например, `req-0123456789abcdef`). Колонка uuid не приняла бы такое
 * значение, поэтому тип приводится к строке до первого обратного события.
 *
 * down() восстанавливает исходную форму: значение correlation_id —
 * технический идентификатор трассировки, его потеря не влияет на статусы
 * уведомлений и проекцию.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE outbox_messages ALTER COLUMN correlation_id TYPE varchar(128) USING correlation_id::text');
    }

    public function down(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->dropColumn('correlation_id');
        });

        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->uuid('correlation_id')->nullable();
        });
    }
};
