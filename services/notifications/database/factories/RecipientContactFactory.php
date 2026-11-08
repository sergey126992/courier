<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\RecipientContact;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<RecipientContact>
 */
class RecipientContactFactory extends Factory
{
    /**
     * @var class-string<RecipientContact>
     */
    protected $model = RecipientContact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fake()->unique()->numberBetween(1, 1000000),
            'email' => fake()->unique()->safeEmail(),
            'locale' => 'ru',
            'source_event_id' => (string) Str::uuid(),
        ];
    }
}
