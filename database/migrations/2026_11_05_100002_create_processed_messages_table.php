<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbox: отметки обработанных событий (раздел 7 ЧТЗ, FR-NTF-131…138).
 *
 * Отметка уникальна в паре «событие — обработчик»: повторная доставка того же
 * события не создаёт второй эффект, а разные обработчики обрабатывают событие
 * независимо. Времени изменения нет: отметка создаётся один раз, а по
 * created_at планировщик удаляет устаревшие записи.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processed_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('message_id');
            $table->string('handler');
            $table->timestampTz('processed_at');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['message_id', 'handler']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processed_messages');
    }
};
