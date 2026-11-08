<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Notification;
use App\Models\NotificationAttempt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationAttempt>
 */
class NotificationAttemptFactory extends Factory
{
    /**
     * @var class-string<NotificationAttempt>
     */
    protected $model = NotificationAttempt::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_id' => Notification::factory(),
            'attempt' => 1,
            'status' => 'sent',
            'error_class' => null,
            'error_code' => null,
            'error_message' => null,
            'provider_code' => null,
        ];
    }
}
