<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Заказы на доставку (раздел 7 ЧТЗ).
 *
 * Цена, прогноз и расстояние фиксируются при создании и не пересчитываются.
 * Статус ограничен набором значений ЧТЗ 5.1 и меняется только доменными методами.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 20)->unique();
            $table->string('tracking_code', 32)->unique();
            // Удаление пользователя или зоны с заказами запрещено (FR-GEO-105).
            $table->foreignId('sender_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('zone_id')->constrained('service_zones')->restrictOnDelete();
            // Статус не входит в fillable модели: меняется только доменными методами.
            $table->string('status', 32)->default('created');
            $table->string('transport_type', 16);
            $table->string('light_vehicle_kind', 16)->nullable();
            $table->jsonb('pickup_address');
            $table->jsonb('dropoff_address');
            $table->jsonb('parcel');
            $table->decimal('distance_km', 8, 2);
            $table->bigInteger('price_amount');
            $table->char('price_currency', 3);
            $table->jsonb('price_breakdown');
            $table->smallInteger('eta_minutes');
            $table->timestampTz('requested_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['transport_type', 'status']);
        });

        // Статусы ЧТЗ 5.1: набор ограничен, правило перехода живёт в домене.
        DB::statement(<<<'SQL'
            ALTER TABLE delivery_orders ADD CONSTRAINT delivery_orders_status_check
            CHECK (status IN ('created', 'assigned', 'accepted', 'picked_up', 'delivered', 'closed', 'cancelled', 'failed'))
            SQL);

        // Индексы для курсорной пагинации: время создания по убыванию.
        DB::statement('CREATE INDEX delivery_orders_sender_created_index ON delivery_orders (sender_id, created_at DESC)');
        DB::statement('CREATE INDEX delivery_orders_status_created_index ON delivery_orders (status, created_at DESC)');
    }

    public function down(): void
    {
        // Индексы и ограничения удаляются вместе с таблицей.
        Schema::dropIfExists('delivery_orders');
    }
};
