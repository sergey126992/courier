<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Order\DeliveryOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Заказ в ответе API (ЧТЗ 8.2).
 *
 * Цена, прогноз и разбивка берутся из заказа как есть: они зафиксированы
 * при создании и не пересчитываются.
 *
 * @mixin DeliveryOrder
 */
final class OrderResource extends ApiResource
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
            'pickup' => Arr::only($order->pickup_address, ['district', 'street', 'house']),
            'dropoff' => Arr::only($order->dropoff_address, ['district', 'street', 'house']),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }
}
