<?php

declare(strict_types=1);

use App\Domain\Identity\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Журнал назначения ролей: записи только добавляются и не удаляются вместе с пользователем.
     */
    public function up(): void
    {
        $roles = [
            Role::Sender->value,
            Role::Courier->value,
            Role::Dispatcher->value,
            Role::Admin->value,
        ];

        Schema::create('role_changes', function (Blueprint $table) use ($roles) {
            $table->id();
            // Удаление пользователя с историей ролей запрещено: журнал должен сохраниться.
            $table->foreignId('user_id')->index()->constrained()->restrictOnDelete();
            $table->enum('previous_role', $roles);
            $table->enum('new_role', $roles);
            // Автор изменения: при удалении автора запись журнала остаётся без него.
            $table->foreignId('changed_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_changes');
    }
};
