<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Попытки назначения курьера на заказ (раздел 7 ЧТЗ, FR-DSP-131…139).
 *
 * Одна строка — одна попытка: история не перезаписывается. Двойное активное
 * назначение отсекается частичными уникальными индексами: у заказа и у курьера
 * одновременно может быть только одна строка в активном статусе.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            // Удаление заказа или курьера с назначениями запрещено (раздел 7 п. 10).
            $table->foreignId('order_id')->constrained('delivery_orders')->restrictOnDelete();
            $table->foreignId('courier_id')->constrained('users')->restrictOnDelete();
            // Статус не входит в fillable модели: меняется только доменными методами.
            $table->string('status', 16);
            // Выбранное правило подбора фиксируется в строке (FR-DSP-163).
            $table->string('strategy', 16);
            $table->decimal('distance_to_pickup_km', 8, 2)->nullable();
            $table->timestampTz('assigned_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('picked_up_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('failure_reason', 32)->nullable();
            $table->text('failure_comment')->nullable();
            $table->timestamps();
        });

        // Статусы задания ЧТЗ 5.2: набор ограничен, правило перехода живёт в домене.
        DB::statement(<<<'SQL'
            ALTER TABLE assignments ADD CONSTRAINT assignments_status_check
            CHECK (status IN ('assigned', 'accepted', 'picked_up', 'delivered', 'failed', 'cancelled'))
            SQL);

        // Активные статусы: назначено, принято, забрано (FR-DSP-134).
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX assignments_active_order_unique ON assignments (order_id)
            WHERE status IN ('assigned', 'accepted', 'picked_up')
            SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX assignments_active_courier_unique ON assignments (courier_id)
            WHERE status IN ('assigned', 'accepted', 'picked_up')
            SQL);

        // История попыток по заказу и загрузка курьера за период.
        DB::statement('CREATE INDEX assignments_order_id_id_index ON assignments (order_id, id DESC)');
        DB::statement('CREATE INDEX assignments_courier_assigned_index ON assignments (courier_id, assigned_at DESC)');
    }

    public function down(): void
    {
        // Индексы и ограничения удаляются вместе с таблицей.
        Schema::dropIfExists('assignments');
    }
};
