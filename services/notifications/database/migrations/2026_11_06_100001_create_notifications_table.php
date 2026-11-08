<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал уведомлений сервиса (ЧТЗ 7.1, FR-NTF-261…267).
 *
 * Дедупликация обеспечена уникальным индексом (event_id, recipient_id,
 * channel): гонка двух потребителей не создаёт второе уведомление
 * (ЧТЗ 7, п. 3). Допустимые статусы защищены CHECK, переходы между ними
 * живут в домене (ЧТЗ 7, п. 4).
 *
 * Внешних ключей на таблицы монолита нет: order_id и recipient_id —
 * идентификаторы, полученные из события (ЧТЗ 7, п. 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id');
            // Заказ известен из события; внешнего ключа на монолит нет.
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('recipient_id');
            $table->string('recipient_role', 16);
            $table->string('channel', 16);
            $table->string('event_type', 64);
            $table->string('template');
            $table->string('status', 16);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error_code', 64)->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampsTz();

            $table->unique(['event_id', 'recipient_id', 'channel']);
            $table->index(['status', 'created_at']);
            $table->index('order_id');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE notifications ADD CONSTRAINT notifications_status_check
            CHECK (status IN ('created', 'queued', 'sent', 'error', 'failed'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
