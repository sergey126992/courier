<?php

declare(strict_types=1);

namespace App\Http\Resources\Dispatch;

use App\Http\Resources\ApiResource;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use Illuminate\Http\Request;

/**
 * Краткая форма назначения для списка (ЧТЗ 8.3, п. 2).
 *
 * Только то, что нужно курьеру для выбора: статус, номер заказа и районы
 * забора и доставки. Полная карточка — AssignmentResource.
 *
 * @mixin Assignment
 */
final class AssignmentSummaryResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Assignment $assignment */
        $assignment = $this->resource;
        /** @var DeliveryOrder $order */
        $order = $assignment->order;

        /** @var array<string, mixed> $pickup */
        $pickup = (array) $order->pickup_address;
        /** @var array<string, mixed> $dropoff */
        $dropoff = (array) $order->dropoff_address;

        return [
            'id' => $assignment->getKey(),
            'status' => $assignment->status->value,
            'status_label' => $assignment->status->label(),
            'order' => [
                'number' => $order->number,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
            ],
            'pickup' => ['district' => (string) ($pickup['district'] ?? '')],
            'dropoff' => ['district' => (string) ($dropoff['district'] ?? '')],
            'assigned_at' => $assignment->assigned_at->toIso8601String(),
        ];
    }
}
