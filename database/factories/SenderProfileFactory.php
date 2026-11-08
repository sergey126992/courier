<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SenderProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SenderProfile>
 */
class SenderProfileFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'phone' => fake()->numerify('+7 9## ###-##-##'),
            'company' => null,
            'default_address' => null,
        ];
    }

    /**
     * Отправитель с названием компании.
     */
    public function withCompany(string $company): static
    {
        return $this->state(fn (array $attributes): array => [
            'company' => $company,
        ]);
    }

    /**
     * Отправитель с адресом по умолчанию.
     *
     * @param array<string, mixed> $address
     */
    public function withDefaultAddress(array $address): static
    {
        return $this->state(fn (array $attributes): array => [
            'default_address' => $address,
        ]);
    }
}
