<?php

declare(strict_types=1);

namespace Database\Factories\Events;

use App\Models\Events\ProcessedMessage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProcessedMessage>
 */
class ProcessedMessageFactory extends Factory
{
    protected $model = ProcessedMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => (string) Str::uuid(),
            'handler' => 'notifications.on_order_created',
            'processed_at' => now(),
        ];
    }
}
