<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NotificationPreference;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationPreference>
 */
class NotificationPreferenceFactory extends Factory
{
    /**
     * @var class-string<NotificationPreference>
     */
    protected $model = NotificationPreference::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => fake()->numberBetween(1, 1000000),
            'event_type' => 'order.created',
            'channels' => ['email'],
            'quiet_hours' => null,
            'locale' => 'ru',
            'critical_override' => false,
        ];
    }
}
