<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ChannelBinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelBinding>
 */
class ChannelBindingFactory extends Factory
{
    /**
     * @var class-string<ChannelBinding>
     */
    protected $model = ChannelBinding::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fake()->numberBetween(1, 1000000),
            'channel' => 'email',
            'address' => fake()->unique()->safeEmail(),
            'verified_at' => null,
            'revoked_at' => null,
        ];
    }
}
