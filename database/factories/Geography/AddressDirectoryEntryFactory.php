<?php

declare(strict_types=1);

namespace Database\Factories\Geography;

use App\Models\Geography\AddressDirectoryEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AddressDirectoryEntry>
 */
class AddressDirectoryEntryFactory extends Factory
{
    protected $model = AddressDirectoryEntry::class;

    /**
     * Значения по умолчанию — внутри тестовой зоны Москвы (55.60–55.90 / 37.40–37.80).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'district' => 'Район '.fake()->unique()->numerify('####'),
            'street' => 'Улица '.fake()->unique()->numerify('####'),
            'house' => (string) fake()->numberBetween(1, 120),
            'latitude' => fake()->randomFloat(6, 55.60, 55.90),
            'longitude' => fake()->randomFloat(6, 37.40, 37.80),
            'is_active' => true,
        ];
    }

    /**
     * Деактивированная запись: в подсказках адреса не участвует.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_active' => false,
        ]);
    }

    /**
     * Адрес по конкретным значениям района, улицы и дома.
     *
     * Координаты можно задать явно; по умолчанию остаются внутри тестовой зоны.
     */
    public function forAddress(
        string $district,
        string $street,
        string $house,
        ?float $latitude = null,
        ?float $longitude = null,
    ): static {
        $state = [
            'district' => $district,
            'street' => $street,
            'house' => $house,
        ];

        if ($latitude !== null) {
            $state['latitude'] = $latitude;
        }

        if ($longitude !== null) {
            $state['longitude'] = $longitude;
        }

        return $this->state(fn (array $attributes): array => $state);
    }
}
