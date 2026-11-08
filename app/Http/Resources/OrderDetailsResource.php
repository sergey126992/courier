<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order\DeliveryOrder;
use App\Models\Order\OrderStatusHistory;
use Illuminate\Http\Request;

/**
 * Карточка заказа: адреса, посылка, цена с разбивкой, прогноз и история (FR-ORD-134).
 *
 * История и её авторы загружаются заранее в контроллере: ресурс не порождает
 * дополнительных запросов к базе.
 *
 * @mixin DeliveryOrder
 */
final class OrderDetailsResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DeliveryOrder $order */
        $order = $this->resource;

        return [
            'number' => $order->number,
            'tracking_code' => $order->tracking_code,
            'status' => $order->status->value,
            'transport' => $order->transport_type->value,
            'light_vehicle_kind' => $order->light_vehicle_kind?->value,
            'distance_km' => $order->distance_km,
            'eta_minutes' => $order->eta_minutes,
            'price' => [
                'amount' => $order->price_amount,
                'currency' => $order->price_currency,
            ],
            'breakdown' => $order->price_breakdown,
            'parcel' => $order->parcel,
            'pickup' => $order->pickup_address,
            'dropoff' => $order->dropoff_address,
            'requested_at' => $order->requested_at->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
            'history' => $order->statusHistory
                ->map(static fn (OrderStatusHistory $record): array => [
                    'from_status' => $record->from_status?->value,
                    'to_status' => $record->to_status->value,
                    'changed_by' => $record->changedBy === null ? null : [
                        'id' => $record->changedBy->id,
                        'name' => $record->changedBy->name,
                    ],
                    'reason' => $record->reason,
                    'created_at' => $record->created_at?->toIso8601String(),
                ])
                ->all(),
        ];
    }
}
