<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Исходящие обратные события сервиса (ЧТЗ 7.1, FR-NTF-281…286).
 *
 * message_id выдаёт база, поэтому публикатор и создатель события не
 * согласуют идентификатор. Выборка публикатора опирается на частичный индекс
 * по неопубликованным строкам: полного скана таблицы нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('message_id');
            $table->string('event_type', 64);
            $table->string('schema_version', 16);
            $table->string('aggregate_type', 32);
            $table->unsignedBigInteger('aggregate_id');
            $table->json('payload');
            $table->uuid('correlation_id')->nullable();
            $table->timestampTz('published_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('available_at');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique('message_id');
        });

        // Идентификатор сообщения по умолчанию выдаёт база (ЧТЗ 7.1).
        DB::statement('ALTER TABLE outbox_messages ALTER COLUMN message_id SET DEFAULT gen_random_uuid()');

        DB::statement(<<<'SQL'
            CREATE INDEX outbox_messages_pending_index
            ON outbox_messages (available_at, created_at, id)
            WHERE published_at IS NULL
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
