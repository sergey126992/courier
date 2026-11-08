<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Справочник зон обслуживания.
 *
 * Код уникален без учёта регистра (функциональный индекс по lower(code)),
 * граница хранится структурой, ставка — в минорных единицах. Запрет удаления
 * зоны, на которую ссылаются заказы, появится вместе с таблицей заказов.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_zones', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64);
            $table->string('name');
            // Прямоугольник или многоугольник в координатах.
            $table->jsonb('boundary');
            // Базовая ставка зоны в копейках.
            $table->integer('base_fee_amount');
            $table->string('currency', 3)->default('RUB');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        // Приложение работает только на PostgreSQL: регистр кода не различается.
        DB::statement('CREATE UNIQUE INDEX service_zones_code_lower_unique ON service_zones (lower(code))');
    }

    public function down(): void
    {
        // Функциональный индекс удаляется вместе с таблицей.
        Schema::dropIfExists('service_zones');
    }
};
