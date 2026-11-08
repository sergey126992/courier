<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Контакты получателей из события user.contact_changed (ЧТЗ 7.1, FR-NTF-231…236).
 *
 * Email хранится только здесь и в channel_bindings (ЧТЗ 7, п. 7). Строка
 * обновляется событиями и в M11 не чистится: объём ограничен числом
 * пользователей (ЧТЗ 7, п. 8). Внешнего ключа на users монолита нет.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipient_contacts', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('email');
            $table->string('locale', 8);
            // Событие, которым контакт обновлён последним.
            $table->uuid('source_event_id')->nullable();
            $table->timestampsTz();

            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipient_contacts');
    }
};
