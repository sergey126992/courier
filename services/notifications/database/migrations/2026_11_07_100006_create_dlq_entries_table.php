<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Очередь необработанных по каналам (ЧТЗ 7.1, FR-NTF-501…509).
 *
 * Запись создаётся при терминальном отказе канала в той же транзакции, что
 * перевод строки `notifications` в `failed`, и хранит только снимок без тела
 * сообщения и персональных данных: причина остаётся кодом и классом ошибки
 * (FR-NTF-502, ЧТЗ 7.3 п. 4).
 *
 * Дедупликация держится уникальным индексом `(notification_id, channel)`,
 * а не проверкой «есть ли уже» перед вставкой (FR-NTF-509, ЧТЗ 7.3 п. 3):
 * одна строка журнала даёт не более одной записи на канал. Внешнего ключа
 * на `notifications` нет: запись DLQ переживает очистку журнала, а повтор по
 * удалённой строке возвращает `404` (ЧТЗ 7.1).
 *
 * Индексы отдают выборку по каналу в порядке времени отказа, по состоянию
 * разбора и сроку давности. Моменты времени — timestamptz в UTC (ЧТЗ 7.3 п. 2).
 * Миграция обратима: down() удаляет таблицу целиком вместе с CHECK и индексами.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dlq_entries', function (Blueprint $table): void {
            $table->id();
            // Ссылка на строку журнала без внешнего ключа (ЧТЗ 7.1).
            $table->unsignedBigInteger('notification_id');
            $table->string('channel', 16);
            $table->string('state', 16)->default('open');
            // Снимок уведомления без тела сообщения и персональных данных.
            $table->uuid('event_id');
            $table->string('event_type', 64);
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('recipient_id');
            $table->string('recipient_role', 16);
            $table->string('error_class', 32)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('correlation_id', 128)->nullable();
            $table->timestampTz('failed_at');
            // Учёт повторов: запись при повторе не удаляется и не переписывается.
            $table->unsignedInteger('replay_count')->default(0);
            $table->timestampTz('last_replayed_at')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();

            // Одна запись на канал строки журнала (FR-NTF-509).
            $table->unique(['notification_id', 'channel']);
            $table->index(['state', 'failed_at']);
            $table->index('resolved_at');
        });

        // Выборка по каналу в порядке времени отказа (FR-NTF-505).
        DB::statement(
            'CREATE INDEX dlq_entries_channel_failed_at_desc_index
             ON dlq_entries (channel, failed_at DESC)'
        );

        // Допустимые состояния защищены CHECK, переходы задаёт домен (ЧТЗ 7.3 п. 5).
        DB::statement(<<<'SQL'
            ALTER TABLE dlq_entries ADD CONSTRAINT dlq_entries_state_check
            CHECK (state IN ('open', 'replayed', 'resolved'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('dlq_entries');
    }
};
