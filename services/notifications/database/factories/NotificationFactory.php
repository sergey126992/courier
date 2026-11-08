<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Notifications\NotificationStatus;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    /**
     * @var class-string<Notification>
     */
    protected $model = Notification::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => (string) Str::uuid(),
            'order_id' => fake()->numberBetween(1, 100000),
            'recipient_id' => fake()->numberBetween(1, 100000),
            'recipient_role' => fake()->randomElement(['sender', 'courier']),
            // Пара «событие — канал» согласована с шаблоном: строка журнала
            // всегда годится для отправки (FR-NTF-247).
            'channel' => 'email',
            'event_type' => 'order.delivered',
            'template' => 'order_delivered_email',
            'tracking_code' => 'TRK-42',
            'status' => NotificationStatus::Created->value,
            'attempts' => 0,
            'last_error_code' => null,
            'sent_at' => null,
        ];
    }

    /**
     * Уведомление канала push: пара «событие — канал» согласована с шаблоном.
     */
    public function push(): static
    {
        return $this->state(fn (): array => [
            'channel' => 'push',
            'event_type' => 'order.delivered',
            'template' => 'order_delivered_push',
        ]);
    }

    /**
     * Уведомление канала Telegram: пара «событие — канал» согласована с шаблоном.
     */
    public function telegram(): static
    {
        return $this->state(fn (): array => [
            'channel' => 'telegram',
            'event_type' => 'order.delivered',
            'template' => 'order_delivered_telegram',
        ]);
    }

    /**
     * Уведомление в терминальном состоянии «отправлено».
     */
    public function sent(): static
    {
        return $this->state(fn (): array => [
            'status' => NotificationStatus::Sent->value,
            'attempts' => 1,
            'sent_at' => now(),
        ]);
    }
}
