<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Последовательность для номера заказа (FR-ORD-105).
 *
 * Номер выдаёт база: приложение не считает его само, поэтому параллельные
 * запросы не получают одинаковых номеров.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS order_number_seq');
        DB::statement('CREATE SEQUENCE order_number_seq START WITH 1 INCREMENT BY 1');
    }

    public function down(): void
    {
        DB::statement('DROP SEQUENCE IF EXISTS order_number_seq');
    }
};
