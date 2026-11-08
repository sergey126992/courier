<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Профиль отправителя: один на пользователя, удаляется вместе с ним.
     */
    public function up(): void
    {
        Schema::create('sender_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('phone', 32)->nullable();
            $table->string('company')->nullable();
            // Адрес по умолчанию хранится структурой; состав полей проверяется на этапе M3.
            $table->jsonb('default_address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sender_profiles');
    }
};
