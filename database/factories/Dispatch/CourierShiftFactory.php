<?php

declare(strict_types=1);

namespace Database\Factories\Dispatch;

use App\Models\Dispatch\CourierShift;
use App\Models\Geography\ServiceZone;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<CourierShift>
 */
class CourierShiftFactory extends Factory
{
    protected $model = CourierShift::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'courier_id' => User::factory()->courier(),
            // Смена без зоны подходит для заказа любой зоны.
            'zone_id' => null,
            'started_at' => now()->subHour(),
            'ended_at' => null,
        ];
    }

    /**
     * Открытая смена: ended_at пуст, ограничение базы держит её до «бесконечности».
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes): array => [
            'ended_at' => null,
        ]);
    }

    /**
     * Закрытая смена с заданным окончанием.
     */
    public function closed(?Carbon $endedAt = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'ended_at' => $endedAt ?? now(),
        ]);
    }

    /**
     * Смена, ограниченная зоной обслуживания (FR-DSP-114).
     */
    public function forZone(ServiceZone $zone): static
    {
        return $this->state(fn (array $attributes): array => [
            'zone_id' => $zone->getKey(),
        ]);
    }

    /**
     * Смена с явным временем начала: нужно для проверки пересечений.
     */
    public function startedAt(Carbon $startedAt): static
    {
        return $this->state(fn (array $attributes): array => [
            'started_at' => $startedAt,
        ]);
    }
}
