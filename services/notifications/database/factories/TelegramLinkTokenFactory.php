<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TelegramLinkToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<TelegramLinkToken>
 */
class TelegramLinkTokenFactory extends Factory
{
    /**
     * @var class-string<TelegramLinkToken>
     */
    protected $model = TelegramLinkToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fake()->numberBetween(1, 1000000),
            // Открытое значение в тестах не нужно: строка описывает хеш.
            'token_hash' => hash('sha256', fake()->unique()->uuid()),
            'expires_at' => Carbon::now('UTC')->addMinutes(15),
            'used_at' => null,
        ];
    }
}
