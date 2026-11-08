<?php

declare(strict_types=1);

namespace App\Http\Resources\Dispatch;

use App\Http\Resources\ApiResource;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Карточка назначения (ЧТЗ 8.3).
 *
 * Полный состав полей: статус и метки этапов, правило подбора и расстояние,
 * курьер и заказ с адресами, весом, ценой и прогнозом. Связи загружает
 * вызывающий слой: ресурс дополнительных запросов не порождает.
 *
 * @mixin Assignment
 */
final class AssignmentResource extends ApiResource
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
        /** @var User $courier */
        $courier = $assignment->courier;

        /** @var array<string, mixed> $parcel */
        $parcel = (array) $order->parcel;

        return [
            'id' => $assignment->getKey(),
            'status' => $assignment->status->value,
            'status_label' => $assignment->status->label(),
            'strategy' => $assignment->strategy->value,
            'distance_to_pickup_km' => $assignment->distance_to_pickup_km,
            'assigned_at' => $assignment->assigned_at->toIso8601String(),
            'accepted_at' => $assignment->accepted_at?->toIso8601String(),
            'picked_up_at' => $assignment->picked_up_at?->toIso8601String(),
            'delivered_at' => $assignment->delivered_at?->toIso8601String(),
            'failed_at' => $assignment->failed_at?->toIso8601String(),
            'failure_reason' => $assignment->failure_reason?->value,
            'failure_comment' => $assignment->failure_comment,
            'courier' => [
                'id' => $courier->getKey(),
                'name' => $courier->name,
                'transport' => $courier->courierProfile?->transport_type->value,
                'light_vehicle_kind' => $courier->courierProfile?->light_vehicle_kind?->value,
            ],
            'order' => [
                'number' => $order->number,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
                'pickup' => $this->address((array) $order->pickup_address),
                'dropoff' => $this->address((array) $order->dropoff_address),
                'parcel' => ['weight_grams' => (int) ($parcel['weight_grams'] ?? 0)],
                'price' => ['amount' => $order->price_amount, 'currency' => $order->price_currency],
                'eta_minutes' => $order->eta_minutes,
                'requested_at' => $order->requested_at->toIso8601String(),
            ],
        ];
    }

    /**
     * Адрес в ответе: только район, улица и дом, без координат.
     *
     * @param array<string, mixed> $address
     * @return array{district: string, street: string, house: string}
     */
    private function address(array $address): array
    {
        return [
            'district' => (string) ($address['district'] ?? ''),
            'street' => (string) ($address['street'] ?? ''),
            'house' => (string) ($address['house'] ?? ''),
        ];
    }
}
