<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Сквозной идентификатор запроса в исходящих событиях (FR-OPS-201, ЧТЗ 8.3).
 *
 * Колонка nullable и строковая: строки, накопленные до M11, остаются без значения и не
 * ломают публикатор — конверт события допускает отсутствие correlation_id
 * (FR-NTF-210). Новые строки заполняет модель из контекста запроса либо
 * новым UUID вне HTTP.
 *
 * Миграция обратима: down() снимает только добавленную колонку.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table): void {
            // Строка, а не uuid: RequestId принимает внешний идентификатор по
            // шаблону 8–128 символов, и correlation_id обязан совпасть с ним
            // (FR-NTF-224, FR-OPS-201).
            $table->string('correlation_id', 128)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table): void {
            $table->dropColumn('correlation_id');
        });
    }
};
