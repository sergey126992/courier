<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Notification;
use App\Models\PushMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PushMessage>
 */
class PushMessageFactory extends Factory
{
    /**
     * @var class-string<PushMessage>
     */
    protected $model = PushMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_id' => Notification::factory(),
            'recipient_id' => fake()->numberBetween(1, 100000),
            'template' => 'order.created',
            'body' => 'Заказ принят.',
            'sent_at' => now(),
        ];
    }
}
