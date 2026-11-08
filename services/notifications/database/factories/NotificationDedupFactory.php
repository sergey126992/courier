<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NotificationDedup;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<NotificationDedup>
 */
class NotificationDedupFactory extends Factory
{
    /**
     * @var class-string<NotificationDedup>
     */
    protected $model = NotificationDedup::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'recipient_id' => fake()->numberBetween(1, 100000),
            'channel' => fake()->randomElement(['email', 'push']),
            'notification_id' => null,
        ];
    }
}
