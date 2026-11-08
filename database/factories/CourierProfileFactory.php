<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Models\CourierProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourierProfile>
 */
class CourierProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'transport_type' => TransportType::OnFoot,
            'light_vehicle_kind' => null,
            'is_active' => true,
        ];
    }

    /**
     * Профиль с заданным транспортом; сочетание проверяется доменным правилом.
     */
    public function forTransport(TransportType $transport, ?LightVehicleKind $kind = null): static
    {
        $transport->assertAccepts($kind);

        return $this->state(fn (array $attributes): array => [
            'transport_type' => $transport,
            'light_vehicle_kind' => $kind,
        ]);
    }

    public function onFoot(): static
    {
        return $this->forTransport(TransportType::OnFoot);
    }

    public function light(LightVehicleKind $kind = LightVehicleKind::Bicycle): static
    {
        return $this->forTransport(TransportType::Light, $kind);
    }

    public function car(): static
    {
        return $this->forTransport(TransportType::Car);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }
}
