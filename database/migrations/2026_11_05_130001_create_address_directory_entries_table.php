<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Справочник адресов: одна запись — один конкретный дом с координатами точки.
 *
 * Адрес уникален без учёта регистра: функциональный уникальный индекс по
 * lower(district), lower(street), lower(house) не даёт завести один и тот же
 * дом дважды. Отдельный индекс по lower(street) обслуживает подсказки,
 * индекс по is_active — фильтр действующих записей.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('address_directory_entries', function (Blueprint $table) {
            $table->id();
            $table->string('district', 255);
            $table->string('street', 255);
            $table->string('house', 64);
            // Координаты точки дома: шесть знаков после запятой, примерно 0,1 м.
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        // Приложение работает только на PostgreSQL: регистр адреса не различается.
        DB::statement(
            'CREATE UNIQUE INDEX address_directory_entries_address_lower_unique'
            .' ON address_directory_entries (lower(district), lower(street), lower(house))'
        );
        // Поиск подсказок идёт по названию улицы без учёта регистра.
        DB::statement(
            'CREATE INDEX address_directory_entries_street_lower_index'
            .' ON address_directory_entries (lower(street))'
        );
    }

    public function down(): void
    {
        // Функциональные индексы удаляются вместе с таблицей.
        Schema::dropIfExists('address_directory_entries');
    }
};
