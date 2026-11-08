<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Поля публикации исходящих событий (раздел 7 ЧТЗ, FR-NTF-101…110).
 *
 * Идентификатор сообщения выдаёт база, поэтому существующие места записи
 * событий не меняются, а уже накопленные строки получают значения при
 * миграции. Выборка публикатора опирается на частичный индекс по
 * неопубликованным строкам, поэтому полного скана таблицы нет; прежний
 * индекс (published_at, created_at) сохраняется.
 *
 * Миграция обратима: down() снимает только добавленные колонки и индексы.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->uuid('message_id')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('available_at')->nullable();
        });

        // Накопленные строки получают идентификатор и время готовности,
        // равное времени создания записи.
        DB::statement('UPDATE outbox_messages SET message_id = gen_random_uuid(), available_at = created_at');
        DB::statement('ALTER TABLE outbox_messages ALTER COLUMN message_id SET NOT NULL');
        DB::statement('ALTER TABLE outbox_messages ALTER COLUMN message_id SET DEFAULT gen_random_uuid()');
        DB::statement('ALTER TABLE outbox_messages ALTER COLUMN available_at SET NOT NULL');

        DB::statement('CREATE UNIQUE INDEX outbox_messages_message_id_unique ON outbox_messages (message_id)');

        DB::statement(<<<'SQL'
            ALTER TABLE outbox_messages ADD CONSTRAINT outbox_messages_attempts_check
            CHECK (attempts >= 0)
            SQL);

        // Частичный индекс публикатора: только ещё не опубликованные строки.
        DB::statement(<<<'SQL'
            CREATE INDEX outbox_messages_pending_index
            ON outbox_messages (available_at, created_at, id)
            WHERE published_at IS NULL
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS outbox_messages_pending_index');
        DB::statement('DROP INDEX IF EXISTS outbox_messages_message_id_unique');
        DB::statement('ALTER TABLE outbox_messages DROP CONSTRAINT IF EXISTS outbox_messages_attempts_check');

        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->dropColumn(['message_id', 'last_error', 'available_at']);
        });
    }
};
