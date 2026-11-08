<?php

declare(strict_types=1);

namespace Database\Factories\Order;

use App\Domain\Order\OrderStatus;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OrderStatusHistory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderStatusHistory>
 */
class OrderStatusHistoryFactory extends Factory
{
    protected $model = OrderStatusHistory::class;

    /**
     * По умолчанию — первая запись истории: заказ создан, автора нет.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => DeliveryOrder::factory(),
            'from_status' => null,
            'to_status' => OrderStatus::Created,
            'changed_by' => null,
            'reason' => null,
        ];
    }

    /**
     * Запись о переходе статуса с автором и причиной.
     */
    public function transition(
        OrderStatus $from,
        OrderStatus $to,
        ?User $author = null,
        ?string $reason = null,
    ): static {
        return $this->state(fn (array $attributes): array => [
            'from_status' => $from,
            'to_status' => $to,
            'changed_by' => $author?->getKey(),
            'reason' => $reason,
        ]);
    }
}
