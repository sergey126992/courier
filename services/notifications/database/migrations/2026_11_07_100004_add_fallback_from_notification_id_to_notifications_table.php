<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ссылка на исходную строку цепочки замещения канала (FR-NTF-478, ЧТЗ 7.1).
 *
 * Колонка нужна, чтобы журнал отличал fallback от независимого уведомления
 * того же события: без ссылки цепочка неотличима от обычных строк. Глубина
 * цепочки не хранится: она вычисляется подсчётом ссылок `FallbackChain`
 * (FR-NTF-479).
 *
 * Внешнего ключа нет: самоссылка каскадом запрещена (ЧТЗ 7.1), а удаление
 * журнала выполняется сроками хранения, а не каскадами. Отдельный индекс
 * не нужен — ссылка читается по одной строке.
 *
 * Миграция обратима: down() снимает колонку.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->bigInteger('fallback_from_notification_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('fallback_from_notification_id');
        });
    }
};
