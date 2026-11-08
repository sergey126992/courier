<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Inbox сервиса (ЧТЗ 7.1, FR-NTF-261…267).
 *
 * Сообщение потока обрабатывается один раз на потребителя: повторная
 * доставка того же message_id отсекается уникальным индексом
 * (message_id, consumer) (ЧТЗ 7, п. 3). Строки чистятся по сроку хранения
 * (ЧТЗ 7, п. 8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbox_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('message_id');
            $table->string('consumer', 64);
            $table->string('stream', 128);
            $table->string('status', 16);
            $table->string('error_code', 64)->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['message_id', 'consumer']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbox_messages');
    }
};
