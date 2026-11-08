<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * История статусов заказа: только добавление (FR-ORD-125, FR-ORD-126).
 *
 * Таблица без updated_at: запись создаётся один раз, изменение и удаление
 * запрещены моделью.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('delivery_orders')->cascadeOnDelete();
            // null — первая запись истории при создании заказа.
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['order_id', 'created_at']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE order_status_history ADD CONSTRAINT order_status_history_to_status_check
            CHECK (to_status IN ('created', 'assigned', 'accepted', 'picked_up', 'delivered', 'closed', 'cancelled', 'failed'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};
