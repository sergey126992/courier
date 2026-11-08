<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\InboxMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<InboxMessage>
 */
class InboxMessageFactory extends Factory
{
    /**
     * @var class-string<InboxMessage>
     */
    protected $model = InboxMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => (string) Str::uuid(),
            'consumer' => 'notifications-service',
            'stream' => 'events.orders',
            'status' => 'processed',
            'error_code' => null,
            'received_at' => now(),
            'processed_at' => now(),
        ];
    }
}
