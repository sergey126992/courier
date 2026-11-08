<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Захват ключа дедупликации в транзакции Inbox (ЧТЗ 7.1, FR-NTF-261…267).
 *
 * Уникальный индекс (event_id, recipient_id, channel) — единственный
 * механизм дедупликации (ЧТЗ 7, п. 3). notification_id заполняется после
 * создания уведомления в той же транзакции, внешнего ключа нет: удаление
 * уведомления не должно освобождать ключ дедупликации, иначе повтор события
 * создал бы второе уведомление (ЧТЗ 7, п. 9).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_dedup', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id');
            $table->unsignedBigInteger('recipient_id');
            $table->string('channel', 16);
            $table->unsignedBigInteger('notification_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['event_id', 'recipient_id', 'channel']);
            // Ключи дедупликации чистятся по сроку хранения (ЧТЗ 7, п. 8).
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_dedup');
    }
};
