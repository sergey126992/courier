<?php

declare(strict_types=1);

namespace Database\Factories\Dispatch;

use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Dispatch\AssignmentStrategy;
use App\Domain\Dispatch\FailureReason;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Assignment>
 */
class AssignmentFactory extends Factory
{
    protected $model = Assignment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => DeliveryOrder::factory(),
            'courier_id' => User::factory()->courier(),
            'status' => AssignmentStatus::Assigned,
            'strategy' => AssignmentStrategy::Nearest,
            'distance_to_pickup_km' => 1.5,
            'assigned_at' => now(),
            'accepted_at' => null,
            'picked_up_at' => null,
            'delivered_at' => null,
            'failed_at' => null,
            'cancelled_at' => null,
            'failure_reason' => null,
            'failure_comment' => null,
        ];
    }

    /**
     * Активное назначение в статусе «назначено».
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AssignmentStatus::Assigned,
            'accepted_at' => null,
            'picked_up_at' => null,
        ]);
    }

    /**
     * Назначение принято курьером.
     */
    public function accepted(?Carbon $acceptedAt = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AssignmentStatus::Accepted,
            'accepted_at' => $acceptedAt ?? now(),
        ]);
    }

    /**
     * Посылка забрана: точка и завершение вручением допустимы.
     */
    public function pickedUp(?Carbon $pickedUpAt = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AssignmentStatus::PickedUp,
            'accepted_at' => now(),
            'picked_up_at' => $pickedUpAt ?? now(),
        ]);
    }

    /**
     * Терминальное назначение: посылка вручена.
     */
    public function delivered(?Carbon $deliveredAt = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AssignmentStatus::Delivered,
            'accepted_at' => now(),
            'picked_up_at' => now(),
            'delivered_at' => $deliveredAt ?? now(),
        ]);
    }

    /**
     * Терминальное назначение: попытка не выполнена с указанной причиной.
     */
    public function failed(FailureReason $reason = FailureReason::Other, ?string $comment = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AssignmentStatus::Failed,
            'accepted_at' => now(),
            'picked_up_at' => now(),
            'failed_at' => now(),
            'failure_reason' => $reason,
            'failure_comment' => $comment,
        ]);
    }

    /**
     * Терминальное назначение: попытка отменена вместе с заказом.
     */
    public function cancelled(?Carbon $cancelledAt = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => AssignmentStatus::Cancelled,
            'cancelled_at' => $cancelledAt ?? now(),
        ]);
    }

    /**
     * Назначение с заданным правилом подбора.
     */
    public function withStrategy(AssignmentStrategy $strategy): static
    {
        return $this->state(fn (array $attributes): array => [
            'strategy' => $strategy,
        ]);
    }
}
