<?php

declare(strict_types=1);

use App\Domain\Geography\Address;
use App\Domain\Geography\Coordinates;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Order\DeliveryOrder;
use App\Domain\Order\OrderNumber;
use App\Domain\Order\OrderStatus;
use App\Domain\Order\TrackingCode;
use App\Domain\Pricing\BreakdownCode;
use App\Domain\Pricing\BreakdownLine;
use App\Domain\Pricing\Distance;
use App\Domain\Pricing\Money;
use App\Domain\Pricing\Parcel;
use App\Domain\Pricing\PriceQuote;

/**
 * Результат расчёта с согласованной разбивкой: сумма строк равна итогу.
 */
function orderQuote(): PriceQuote
{
    return new PriceQuote(
        transport: TransportType::Light,
        lightVehicleKind: LightVehicleKind::Bicycle,
        distance: Distance::fromKilometers(1.8),
        etaMinutes: 15,
        total: Money::rub(19400),
        lines: [
            new BreakdownLine(BreakdownCode::Base, 'Базовая ставка', Money::rub(14900)),
            new BreakdownLine(BreakdownCode::Distance, '1.8 км', Money::rub(4500)),
            new BreakdownLine(BreakdownCode::Surge, 'Надбавка за час пик', Money::rub(0)),
        ],
    );
}

/**
 * Адрес забора для тестов домена заказа.
 */
function orderPickupAddress(): Address
{
    return new Address('Тверской', 'Тверская', '7', new Coordinates(55.755826, 37.6173));
}

/**
 * Адрес доставки для тестов домена заказа.
 */
function orderDropoffAddress(): Address
{
    return new Address('Тверской', 'Тверская', '9', new Coordinates(55.768279, 37.6173));
}

/**
 * Момент заявки вне часов пик и не в прошлом: цена тестов предсказуема.
 */
function orderRequestedAt(): string
{
    return now()->addDay()->setTime(15, 0)->toIso8601String();
}

/**
 * Тело запроса создания заказа с разумными значениями по умолчанию.
 *
 * Маршрут длиной 1.8 км, адреса различаются домом: цена — 14900 + 2500 × 1.8.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function orderPayload(array $overrides = []): array
{
    [$pickup, $dropoff] = estimateRoute(1.8);
    $pickup['house'] = '7';
    $dropoff['house'] = '9';

    return array_merge([
        'transport' => TransportType::Light->value,
        'light_vehicle_kind' => LightVehicleKind::Bicycle->value,
        'pickup' => $pickup,
        'dropoff' => $dropoff,
        'parcel' => [
            'weight_grams' => 1500,
            'dimensions' => ['length' => 30, 'width' => 20, 'height' => 10],
        ],
        'requested_at' => orderRequestedAt(),
    ], $overrides);
}

/**
 * Заголовки запроса с ключом идемпотентности.
 *
 * @return array<string, string>
 */
function idempotencyHeader(string $key = 'order-key-0123456789'): array
{
    return ['Idempotency-Key' => $key];
}

/**
 * Агрегат заказа в заданном статусе.
 */
function orderAggregate(OrderStatus $status = OrderStatus::Created): DeliveryOrder
{
    return new DeliveryOrder(
        number: OrderNumber::fromSequence(1, 2026),
        trackingCode: TrackingCode::fromString('trk_9f3a1c5e'),
        pickupAddress: orderPickupAddress(),
        dropoffAddress: orderDropoffAddress(),
        parcel: new Parcel(1500, 30, 20, 10),
        quote: orderQuote(),
        status: $status,
    );
}
