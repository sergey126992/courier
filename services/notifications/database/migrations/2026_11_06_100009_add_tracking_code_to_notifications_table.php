<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Код трекинга в журнале уведомлений (FR-NTF-251).
 *
 * Текст письма и push содержит ссылку трекинга, а сервис не читает базу
 * монолита: `tracking_code` приходит в payload события заказа и сохраняется
 * вместе с уведомлением, чтобы отправка и повтор работали без события.
 * Персональных данных в поле нет (FR-NTF-235).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->string('tracking_code', 32)->nullable()->after('order_id');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropColumn('tracking_code');
        });
    }
};
