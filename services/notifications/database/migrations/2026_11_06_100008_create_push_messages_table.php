<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Журнал фейкового push и страницы симулятора (ЧТЗ 7.1, FR-NTF-251…258).
 *
 * Таблица только на добавление: отправленные сообщения не переписываются
 * (ЧТЗ 7, п. 6). Получатель хранится снимком, поэтому удаление уведомления
 * лишь обнуляет ссылку на него, а внешнего ключа на users нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->nullable()->constrained('notifications')->nullOnDelete();
            $table->unsignedBigInteger('recipient_id');
            $table->string('template');
            $table->text('body');
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        // Журнал получателя в обратном порядке: последнее сообщение сверху.
        DB::statement('CREATE INDEX push_messages_recipient_created_index ON push_messages (recipient_id, created_at DESC)');
    }

    public function down(): void
    {
        Schema::dropIfExists('push_messages');
    }
};
