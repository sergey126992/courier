<?php

declare(strict_types=1);

namespace App\Services\Order;

use App\Domain\Geography\Address;
use App\Domain\Geography\Coordinates;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Order\DeliveryOrder;
use App\Domain\Order\OrderNumber;
use App\Domain\Order\TrackingCode;
use App\Domain\Pricing\BreakdownCode;
use App\Domain\Pricing\BreakdownLine;
use App\Domain\Pricing\Distance;
use App\Domain\Pricing\Money;
use App\Domain\Pricing\Parcel;
use App\Domain\Pricing\PriceQuote;
use App\Models\Order\DeliveryOrder as DeliveryOrderModel;

/**
 * Восстанавливает доменный агрегат заказа из модели.
 *
 * Хранилище знает о домене, домен о хранилище — нет. Восстановление нужно
 * переходам статуса: проверку перехода делает агрегат, а не модель.
 */
final readonly class OrderAggregateFactory
{
    public function fromModel(DeliveryOrderModel $order): DeliveryOrder
    {
        return new DeliveryOrder(
            number: OrderNumber::fromString((string) $order->number),
            trackingCode: TrackingCode::fromString((string) $order->tracking_code),
            pickupAddress: $this->address((array) $order->pickup_address),
            dropoffAddress: $this->address((array) $order->dropoff_address),
            parcel: $this->parcel((array) $order->parcel),
            quote: $this->quote($order),
            status: $order->status,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function address(array $data): Address
    {
        return new Address(
            district: (string) ($data['district'] ?? ''),
            street: (string) ($data['street'] ?? ''),
            house: (string) ($data['house'] ?? ''),
            coordinates: new Coordinates(
                (float) ($data['latitude'] ?? 0),
                (float) ($data['longitude'] ?? 0),
            ),
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function parcel(array $data): Parcel
    {
        $declared = $data['declared_value'] ?? null;

        return new Parcel(
            weightGrams: (int) ($data['weight_grams'] ?? 0),
            lengthCm: (int) ($data['length_cm'] ?? 0),
            widthCm: (int) ($data['width_cm'] ?? 0),
            heightCm: (int) ($data['height_cm'] ?? 0),
            declaredValue: is_array($declared)
                ? Money::fromMinor((int) $declared['amount'], (string) $declared['currency'])
                : null,
        );
    }

    /**
     * Зафиксированные в заказе цена, разбивка, расстояние и прогноз.
     */
    private function quote(DeliveryOrderModel $order): PriceQuote
    {
        $currency = (string) $order->price_currency;

        return new PriceQuote(
            transport: $order->transport_type,
            lightVehicleKind: $order->light_vehicle_kind instanceof LightVehicleKind
                ? $order->light_vehicle_kind
                : null,
            distance: Distance::fromKilometers((float) $order->distance_km),
            etaMinutes: $order->eta_minutes,
            total: Money::fromMinor($order->price_amount, $currency),
            lines: $this->lines($order->price_breakdown, $currency),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $breakdown
     * @return list<BreakdownLine>
     */
    private function lines(array $breakdown, string $currency): array
    {
        $lines = [];

        foreach ($breakdown as $line) {
            $lines[] = new BreakdownLine(
                BreakdownCode::from((string) $line['code']),
                (string) $line['label'],
                Money::fromMinor((int) $line['amount'], $currency),
            );
        }

        return $lines;
    }
}
