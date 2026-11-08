<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order\DeliveryOrder;
use Illuminate\Http\Request;

/**
 * Заказ в списке: краткая форма без адресов и истории (ЧТЗ 8.3).
 *
 * @mixin DeliveryOrder
 */
final class OrderSummaryResource extends ApiResource
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
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }
}
