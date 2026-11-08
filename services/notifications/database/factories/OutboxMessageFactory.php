<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OutboxMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OutboxMessage>
 */
class OutboxMessageFactory extends Factory
{
    /**
     * @var class-string<OutboxMessage>
     */
    protected $model = OutboxMessage::class;

    /**
     * Идентификатор сообщения не задаётся: его выдаёт база по умолчанию.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_type' => 'notification.sent',
            'schema_version' => 'v1',
            'aggregate_type' => 'order',
            'aggregate_id' => fake()->numberBetween(1, 100000),
            'payload' => ['notification_id' => fake()->numberBetween(1, 100000)],
            'correlation_id' => (string) Str::uuid(),
            'published_at' => null,
            'attempts' => 0,
            'last_error' => null,
            'available_at' => now(),
        ];
    }
}
