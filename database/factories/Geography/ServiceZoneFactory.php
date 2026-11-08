<?php

declare(strict_types=1);

namespace Database\Factories\Geography;

use App\Models\Geography\ServiceZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceZone>
 */
class ServiceZoneFactory extends Factory
{
    protected $model = ServiceZone::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'zone-'.fake()->unique()->numerify('####'),
            'name' => 'Зона '.fake()->unique()->city(),
            'boundary' => [
                'type' => 'rectangle',
                'south_west' => ['latitude' => 55.70, 'longitude' => 37.50],
                'north_east' => ['latitude' => 55.80, 'longitude' => 37.70],
            ],
            'base_fee_amount' => fake()->numberBetween(9900, 49900),
            'currency' => 'RUB',
            'is_active' => true,
        ];
    }

    /**
     * Деактивированная зона: в проверке новых адресов не участвует.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Зона с явно заданной границей.
     *
     * @param array<string, mixed> $boundary
     */
    public function withBoundary(array $boundary): static
    {
        return $this->state(fn (array $attributes): array => [
            'boundary' => $boundary,
        ]);
    }
}
