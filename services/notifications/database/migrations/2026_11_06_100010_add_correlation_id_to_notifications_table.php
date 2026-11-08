<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сквозной correlation_id исходного события заказа в строке журнала (FR-NTF-281, FR-OPS-201).
 *
 * Обратное событие обязано вернуть тот же correlation_id, с которым пришло
 * событие заказа. Поллер отправляет уведомление позже, чем создаётся строка,
 * поэтому идентификатор сохраняется в журнале, а не берётся из контекста
 * запроса. Колонка nullable: строки до M11 и события без идентификатора
 * остаются валидными, обратное событие тогда использует event_id.
 *
 * Миграция обратима: down() снимает только добавленную колонку.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            // Строка, а не uuid: request_id монолита принимается по шаблону
            // 8–128 символов и не обязан быть UUID (FR-OPS-201).
            $table->string('correlation_id', 128)->nullable()->after('event_id');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('correlation_id');
        });
    }
};
