<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ключи идемпотентности создания заказа (FR-ORD-108…112).
 *
 * Ключ уникален в пределах пользователя, срок хранения ограничен: по expires_at
 * планировщик удаляет устаревшие записи.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('key', 128);
            $table->string('request_hash', 64);
            $table->string('status', 16);
            $table->smallInteger('response_code')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('expires_at');
            $table->timestamps();

            $table->unique(['user_id', 'key']);
            $table->index('expires_at');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE idempotency_keys ADD CONSTRAINT idempotency_keys_status_check
            CHECK (status IN ('in_progress', 'completed'))
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
