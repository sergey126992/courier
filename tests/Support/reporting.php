<?php

declare(strict_types=1);

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Reporting\ReportPeriod;
use App\Models\Dispatch\Assignment;
use App\Models\Dispatch\CourierShift;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Период явного набора ЧТЗ, раздел 5: с 1 по 6 ноября 2026 по +03:00.
 */
function reportingExplicitPeriod(): ReportPeriod
{
    return new ReportPeriod(
        new DateTimeImmutable('2026-11-01T00:00:00+03:00'),
        new DateTimeImmutable('2026-11-06T00:00:00+03:00'),
        366 * 86400,
    );
}

/**
 * Момент времени: даты набора заданы в московском времени (+03:00).
 */
function reportingMoment(string $value): Carbon
{
    return Carbon::parse($value, 'Europe/Moscow');
}

/**
 * Заказ с заданными типом транспорта, ценой, ETA и временем заявки.
 */
function reportingOrder(
    TransportType $transport,
    int $price,
    int $etaMinutes,
    string $requestedAt,
    ?string $cancelledAt = null,
    string $currency = 'RUB',
): DeliveryOrder {
    return DeliveryOrder::factory()->create([
        'transport_type' => $transport,
        'light_vehicle_kind' => $transport === TransportType::Light ? LightVehicleKind::Bicycle : null,
        'price_amount' => $price,
        'price_currency' => $currency,
        'eta_minutes' => $etaMinutes,
        'requested_at' => reportingMoment($requestedAt),
        'cancelled_at' => $cancelledAt === null ? null : reportingMoment($cancelledAt),
    ]);
}

/**
 * Вручение заказа курьеру в указанный момент.
 */
function reportingDelivery(DeliveryOrder $order, User $courier, string $deliveredAt): Assignment
{
    return Assignment::factory()
        ->delivered(reportingMoment($deliveredAt))
        ->create([
            'order_id' => $order->getKey(),
            'courier_id' => $courier->getKey(),
        ]);
}

/**
 * Смена курьера с заданными границами; открытая смена не имеет окончания.
 */
function reportingShift(User $courier, string $startedAt, ?string $endedAt = null): CourierShift
{
    $factory = CourierShift::factory()
        ->for($courier, 'courier')
        ->startedAt(reportingMoment($startedAt));

    return ($endedAt === null ? $factory->open() : $factory->closed(reportingMoment($endedAt)))->create();
}

/**
 * Явный набор раздела 5 ЧТЗ: шесть заказов и три курьера с вручениями.
 *
 * O4 создан до периода и вручён в нём, O3 отменён. Ожидания тестов:
 * orders_created = 5, orders_cancelled = 1, orders_delivered = 4,
 * revenue = 180000, average_eta_minutes = 69.0, доли 25/25/50, места 2/2/1.
 * Смены: C1 — 9 ч, C2 — 6 ч, C3 — 6 ч, всего 21 ч (shift_hours = 21.0).
 *
 * @return array{orders: array<string, DeliveryOrder>, couriers: array<string, User>}
 */
function reportingDataset(): array
{
    $couriers = [
        'C1' => User::factory()->courier()->create(),
        'C2' => User::factory()->courier()->create(),
        'C3' => User::factory()->courier()->create(),
    ];

    $orders = [
        'O1' => reportingOrder(TransportType::Light, 45000, 90, '2026-11-01 12:00:00'),
        'O2' => reportingOrder(TransportType::Car, 90000, 60, '2026-11-02 13:00:00'),
        'O3' => reportingOrder(TransportType::Light, 30000, 45, '2026-11-03 11:00:00', '2026-11-03 12:00:00'),
        'O4' => reportingOrder(TransportType::OnFoot, 20000, 30, '2026-10-31 02:00:00'),
        'O5' => reportingOrder(TransportType::Car, 50000, 120, '2026-11-04 14:00:00'),
        'O6' => reportingOrder(TransportType::OnFoot, 25000, 30, '2026-11-05 12:00:00'),
    ];

    reportingDelivery($orders['O1'], $couriers['C1'], '2026-11-01 15:00:00');
    reportingDelivery($orders['O4'], $couriers['C1'], '2026-11-01 13:00:00');
    reportingDelivery($orders['O2'], $couriers['C2'], '2026-11-02 16:00:00');
    reportingDelivery($orders['O6'], $couriers['C3'], '2026-11-05 13:00:00');

    // Смены явного набора ЧТЗ: все три целиком внутри периода.
    reportingShift($couriers['C1'], '2026-11-01 09:00:00', '2026-11-01 18:00:00');
    reportingShift($couriers['C2'], '2026-11-02 10:00:00', '2026-11-02 16:00:00');
    reportingShift($couriers['C3'], '2026-11-05 08:00:00', '2026-11-05 14:00:00');

    return ['orders' => $orders, 'couriers' => $couriers];
}

/**
 * Адрес отчёта за переданные границы периода.
 */
function reportingUrl(string $from, string $to): string
{
    return '/api/v1/reports/orders?'.http_build_query(['from' => $from, 'to' => $to]);
}

/**
 * Адрес отчёта за период явного набора раздела 5 ЧТЗ.
 */
function reportingPeriodUrl(): string
{
    return reportingUrl('2026-11-01T00:00:00+03:00', '2026-11-06T00:00:00+03:00');
}
