<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Проекция результата доставки уведомлений в карточке заказа (ЧТЗ 7.2, FR-NTF-284).
 *
 * Заполняется только в режиме `service` из обратных событий сервиса
 * (`notification.sent` / `notification.failed`). Внешнего ключа на таблицы
 * сервиса нет: базы независимы, связь поддерживается идентификаторами
 * (ЧТЗ 7, п. 2). Запись заказа удаляется — проекция уходит вместе с ней.
 *
 * Идемпотентность держат уникальные индексы, а не проверка перед вставкой
 * (ЧТЗ 7, п. 3): повтор обратного события с тем же `source_event_id` не
 * создаёт вторую строку, а уведомление имеет не больше одной строки проекции.
 *
 * Миграция обратима: down() удаляет только эту таблицу.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_notification_status', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('delivery_orders')->cascadeOnDelete();
            // Идентификатор уведомления сервиса: внешнего ключа на его базу нет.
            $table->unsignedBigInteger('notification_id');
            $table->string('channel', 16);
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->string('error_code', 64)->nullable();
            // Идентификатор обратного события, породившего строку проекции.
            $table->uuid('source_event_id');
            // Момент события из payload: приходит из сервиса в UTC.
            $table->timestampTz('occurred_at');
            $table->timestamps();

            $table->unique('notification_id');
            $table->unique('source_event_id');
            $table->index(['order_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_notification_status');
    }
};
