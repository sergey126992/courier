<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Попытки отправки (ЧТЗ 7.1, FR-NTF-271…278).
 *
 * Таблица только на добавление: попытка фиксируется один раз и не
 * переписывается, поэтому времени изменения нет (ЧТЗ 7, п. 6). Номер попытки
 * уникален в пределах уведомления, попытки удаляются вместе с уведомлением.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('notifications')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('status', 16);
            $table->string('error_class')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->string('provider_code', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['notification_id', 'attempt']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_attempts');
    }
};
