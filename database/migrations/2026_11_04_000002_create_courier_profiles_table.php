<?php

declare(strict_types=1);

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Профиль курьера: один на пользователя, удаляется вместе с ним.
     */
    public function up(): void
    {
        Schema::create('courier_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('transport_type', [
                TransportType::OnFoot->value,
                TransportType::Light->value,
                TransportType::Car->value,
            ]);
            $table->enum('light_vehicle_kind', [
                LightVehicleKind::Bicycle->value,
                LightVehicleKind::Scooter->value,
                LightVehicleKind::EScooter->value,
                LightVehicleKind::EBike->value,
            ])->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Вид лёгкого транспорта допустим только при типе «лёгкий транспорт».
        // Имя не должно совпадать с автоматическим именем CHECK-ограничения колонки.
        DB::statement(
            'ALTER TABLE courier_profiles ADD CONSTRAINT courier_profiles_light_kind_check '
            ."CHECK (light_vehicle_kind IS NULL OR transport_type = 'light')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('courier_profiles');
    }
};
