<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DeviceToken;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeviceToken>
 */
class DeviceTokenFactory extends Factory
{
    /**
     * @var class-string<DeviceToken>
     */
    protected $model = DeviceToken::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $token = 'fcm-'.Str::random(120);

        return [
            'user_id' => fake()->numberBetween(1, 100000),
            'token' => $token,
            'fingerprint' => DeviceToken::fingerprint($token),
            'platform' => fake()->randomElement(['android', 'ios', 'web']),
            'locale' => 'ru',
            'app_version' => '1.0.0',
            'last_seen_at' => now(),
            'revoked_at' => null,
        ];
    }

    /**
     * Отозванный токен: в адресации не участвует.
     */
    public function revoked(): static
    {
        return $this->state(fn (): array => [
            'revoked_at' => now(),
        ]);
    }
}
