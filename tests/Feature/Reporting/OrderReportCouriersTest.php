<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Блок загрузки курьеров: секунды смен периода считает второй SQL отчёта
 * (FR-RPT-119, FR-RPT-120). Целые часы JSON отдаёт без дробной части,
 * поэтому ожидания сравниваются через `toEqual`, как доли разреза.
 */
beforeEach(function (): void {
    Sanctum::actingAs(User::factory()->admin()->create());
});

it('обрезает открытую смену правой границей периода', function (): void {
    $courier = User::factory()->courier()->create();
    // Смена открыта: она считается до правой границы 2026-11-06T00:00:00+03:00.
    reportingShift($courier, '2026-11-05 20:00:00');

    $couriers = $this->getJson(reportingPeriodUrl())->assertOk()->json('couriers');

    expect($couriers['shift_hours'])->toEqual(4.0)
        // Курьер без вручений активным не считается (FR-RPT-117).
        ->and($couriers['active_couriers'])->toBe(0)
        ->and($couriers['deliveries_per_courier'])->toBeNull();
});

it('даёт длительность периода для смены, покрывающей его целиком', function (): void {
    $courier = User::factory()->courier()->create();
    reportingShift($courier, '2026-10-25 00:00:00', '2026-11-10 00:00:00');

    // Период — ровно пять суток, то есть 120 часов.
    expect($this->getJson(reportingPeriodUrl())->assertOk()->json('couriers.shift_hours'))->toEqual(120.0);
});

it('обрезает смену по левой границе и не считает смены вне периода', function (): void {
    $crossing = User::factory()->courier()->create();
    $before = User::factory()->courier()->create();
    $after = User::factory()->courier()->create();

    // Пересечение с периодом: с 2026-11-01 00:00 до 02:00 — два часа.
    reportingShift($crossing, '2026-10-31 22:00:00', '2026-11-01 02:00:00');
    reportingShift($before, '2026-10-20 10:00:00', '2026-10-21 10:00:00');
    // Начинается ровно в правой границе периода — смена вне его.
    reportingShift($after, '2026-11-06 00:00:00', '2026-11-06 05:00:00');

    expect($this->getJson(reportingPeriodUrl())->assertOk()->json('couriers.shift_hours'))->toEqual(2.0);
});

it('учитывает часы курьера без вручений, но не делает его активным', function (): void {
    $courier = User::factory()->courier()->create();
    reportingShift($courier, '2026-11-02 09:00:00', '2026-11-02 18:00:00');

    $json = $this->getJson(reportingPeriodUrl())->assertOk()->json();

    expect($json['couriers']['shift_hours'])->toEqual(9.0)
        ->and($json['couriers']['active_couriers'])->toBe(0)
        ->and($json['couriers']['deliveries_per_courier'])->toBeNull()
        // Блок курьеров повторяет итоги намеренно: значения не расходятся.
        ->and($json['totals']['active_couriers'])->toBe(0)
        ->and($json['totals']['deliveries_per_courier'])->toBeNull();
});

it('округляет часы до одного знака half-up', function (): void {
    $courier = User::factory()->courier()->create();
    // 15 минут = 0.25 часа: half-up даёт 0.3, half-even оставил бы 0.2.
    reportingShift($courier, '2026-11-03 10:00:00', '2026-11-03 10:15:00');

    expect($this->getJson(reportingPeriodUrl())->assertOk()->json('couriers.shift_hours'))->toBe(0.3);
});

it('отдаёт ноль часов, когда смен нет', function (): void {
    expect($this->getJson(reportingPeriodUrl())->assertOk()->json('couriers'))
        ->toEqual([
            'active_couriers' => 0,
            'deliveries_per_courier' => null,
            'shift_hours' => 0.0,
        ]);
});

it('даёт те же часы при периоде в Europe/Berlin', function (): void {
    reportingDataset();

    $moscow = $this->getJson(reportingPeriodUrl())->assertOk()->json('couriers');
    // Те же моменты времени: 2026-11-01T00:00:00+03:00 = 2026-10-31T22:00:00+01:00.
    $berlin = $this->getJson(reportingUrl('2026-10-31T22:00:00+01:00', '2026-11-05T22:00:00+01:00'))
        ->assertOk()
        ->json('couriers');

    expect($berlin)->toEqual($moscow)
        ->and($berlin['shift_hours'])->toEqual(21.0);
});
