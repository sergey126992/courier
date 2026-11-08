<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Notifications\DlqState;
use App\Domain\Notifications\NotificationErrorCode;
use App\Models\DlqEntry;
use App\Models\Notification;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DlqEntry>
 */
class DlqEntryFactory extends Factory
{
    /**
     * @var class-string<DlqEntry>
     */
    protected $model = DlqEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'notification_id' => Notification::factory(),
            'channel' => 'email',
            'state' => DlqState::Open->value,
            'event_id' => (string) Str::uuid(),
            'event_type' => 'order.delivered',
            'order_id' => 42,
            'recipient_id' => 7,
            'recipient_role' => 'sender',
            'error_class' => 'business',
            'error_code' => NotificationErrorCode::RECIPIENT_UNKNOWN,
            'attempts' => 5,
            'correlation_id' => 'corr-00000001',
            'failed_at' => now(),
            'replay_count' => 0,
            'last_replayed_at' => null,
            'resolved_at' => null,
        ];
    }

    /**
     * Запись в состоянии «повтор поставлен».
     */
    public function replayed(): static
    {
        return $this->state(fn (): array => [
            'state' => DlqState::Replayed->value,
            'replay_count' => 1,
            'last_replayed_at' => now(),
        ]);
    }

    /**
     * Разобранная запись: повтор отклоняется.
     */
    public function resolved(): static
    {
        return $this->state(fn (): array => [
            'state' => DlqState::Resolved->value,
            'resolved_at' => now(),
        ]);
    }
}
