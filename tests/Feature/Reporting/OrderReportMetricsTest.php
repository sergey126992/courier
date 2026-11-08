<?php

declare(strict_types=1);

use App\Domain\Identity\TransportType;
use App\Models\Dispatch\Assignment;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Тесты метрик сводного отчёта на явном наборе раздела 5 ЧТЗ.
 */
beforeEach(function (): void {
    Sanctum::actingAs(User::factory()->admin()->create());
});

it('считает метрики на явном наборе раздела 5 ЧТЗ', function (): void {
    reportingDataset();

    $response = $this->getJson(reportingPeriodUrl())->assertOk();

    expect($response->json('totals'))->toEqual([
        'orders_created' => 5,
        'orders_cancelled' => 1,
        'orders_delivered' => 4,
        'revenue' => 180000,
        'average_check' => 45000,
        'average_eta_minutes' => 69.0,
        'active_couriers' => 3,
        'deliveries_per_courier' => 1.33,
    ]);

    expect($response->json('couriers'))->toEqual([
        'active_couriers' => 3,
        'deliveries_per_courier' => 1.33,
        'shift_hours' => 21.0,
    ]);

    expect($response->json('by_transport'))->toEqual([
        [
            'transport_type' => 'on_foot',
            'orders_created' => 1,
            'orders_delivered' => 2,
            'revenue' => 45000,
            'share_percent' => 25.0,
            'rank' => 2,
        ],
        [
            'transport_type' => 'light',
            'orders_created' => 2,
            'orders_delivered' => 1,
            'revenue' => 45000,
            'share_percent' => 25.0,
            'rank' => 2,
        ],
        [
            'transport_type' => 'car',
            'orders_created' => 2,
            'orders_delivered' => 1,
            'revenue' => 90000,
            'share_percent' => 50.0,
            'rank' => 1,
        ],
    ]);
});

it('учитывает заказ, созданный до периода, если вручение попало в период', function (): void {
    $courier = User::factory()->courier()->create();
    $order = reportingOrder(TransportType::Car, 70000, 999, '2026-10-01 10:00:00');
    reportingDelivery($order, $courier, '2026-11-02 10:00:00');

    $json = $this->getJson(reportingPeriodUrl())->assertOk()->json();

    expect($json['totals']['orders_created'])->toBe(0)
        ->and($json['totals']['orders_delivered'])->toBe(1)
        ->and($json['totals']['revenue'])->toBe(70000)
        ->and($json['totals']['average_check'])->toBe(70000)
        // ETA считается по созданным в периоде заказам, поэтому среднее пустое.
        ->and($json['totals']['average_eta_minutes'])->toBeNull()
        ->and($json['by_transport'][2]['transport_type'])->toBe('car')
        ->and($json['by_transport'][2]['orders_created'])->toBe(0)
        ->and($json['by_transport'][2]['orders_delivered'])->toBe(1)
        ->and($json['by_transport'][2]['revenue'])->toBe(70000)
        ->and($json['by_transport'][2]['share_percent'])->toEqual(100.0)
        ->and($json['by_transport'][2]['rank'])->toBe(1);
});

it('не задваивает вручение и выручку при повторных попытках назначения', function (): void {
    $courier = User::factory()->courier()->create();
    $order = reportingOrder(TransportType::Light, 45000, 90, '2026-11-01 12:00:00');

    Assignment::factory()->failed()->create([
        'order_id' => $order->getKey(),
        'courier_id' => $courier->getKey(),
    ]);
    reportingDelivery($order, $courier, '2026-11-01 15:00:00');

    $json = $this->getJson(reportingPeriodUrl())->assertOk()->json();

    expect($json['totals']['orders_delivered'])->toBe(1)
        ->and($json['totals']['revenue'])->toBe(45000)
        ->and($json['totals']['average_check'])->toBe(45000)
        ->and($json['by_transport'][1]['orders_delivered'])->toBe(1)
        ->and($json['by_transport'][1]['revenue'])->toBe(45000);
});

it('не включает отменённый заказ в выручку', function (): void {
    reportingOrder(TransportType::Light, 30000, 45, '2026-11-03 11:00:00', '2026-11-03 12:00:00');

    $json = $this->getJson(reportingPeriodUrl())->assertOk()->json();

    expect($json['totals']['orders_created'])->toBe(1)
        ->and($json['totals']['orders_cancelled'])->toBe(1)
        ->and($json['totals']['orders_delivered'])->toBe(0)
        ->and($json['totals']['revenue'])->toBe(0)
        ->and($json['totals']['average_check'])->toBeNull();
});

it('всегда отдаёт три строки разреза в доменном порядке при данных одного типа', function (): void {
    $courier = User::factory()->courier()->create();
    $order = reportingOrder(TransportType::Car, 90000, 60, '2026-11-02 13:00:00');
    reportingDelivery($order, $courier, '2026-11-02 16:00:00');

    $rows = $this->getJson(reportingPeriodUrl())->assertOk()->json('by_transport');

    expect(array_column($rows, 'transport_type'))->toBe(['on_foot', 'light', 'car'])
        ->and(array_column($rows, 'orders_created'))->toBe([0, 0, 1])
        ->and(array_column($rows, 'revenue'))->toBe([0, 0, 90000])
        ->and(array_column($rows, 'share_percent'))->toEqual([0.0, 0.0, 100.0])
        ->and(array_column($rows, 'rank'))->toBe([2, 2, 1]);
});

it('присваивает равные места при равной выручке с пропуском следующего', function (): void {
    $courier = User::factory()->courier()->create();

    $light = reportingOrder(TransportType::Light, 50000, 30, '2026-11-01 10:00:00');
    $car = reportingOrder(TransportType::Car, 50000, 30, '2026-11-01 10:00:00');
    $onFoot = reportingOrder(TransportType::OnFoot, 10000, 30, '2026-11-01 10:00:00');

    reportingDelivery($light, $courier, '2026-11-01 12:00:00');
    reportingDelivery($car, $courier, '2026-11-01 13:00:00');
    reportingDelivery($onFoot, $courier, '2026-11-01 14:00:00');

    $rows = $this->getJson(reportingPeriodUrl())->assertOk()->json('by_transport');

    expect(array_column($rows, 'rank'))->toBe([3, 1, 1]);
});

it('отдаёт нули и null для пустого периода', function (): void {
    $json = $this->getJson(reportingPeriodUrl())->assertOk()->json();

    expect($json['totals'])->toBe([
        'orders_created' => 0,
        'orders_cancelled' => 0,
        'orders_delivered' => 0,
        'revenue' => 0,
        'average_check' => null,
        'average_eta_minutes' => null,
        'active_couriers' => 0,
        'deliveries_per_courier' => null,
    ]);

    expect(array_column($json['by_transport'], 'transport_type'))->toBe(['on_foot', 'light', 'car'])
        ->and(array_column($json['by_transport'], 'orders_created'))->toBe([0, 0, 0])
        ->and(array_column($json['by_transport'], 'orders_delivered'))->toBe([0, 0, 0])
        ->and(array_column($json['by_transport'], 'revenue'))->toBe([0, 0, 0])
        ->and(array_column($json['by_transport'], 'share_percent'))->toEqual([0.0, 0.0, 0.0])
        ->and(array_column($json['by_transport'], 'rank'))->toBe([1, 1, 1]);
});

it('включает from, исключает to и включает момент за микросекунду до to', function (): void {
    reportingOrder(TransportType::Light, 10000, 10, '2026-10-31 23:59:59.999999');
    reportingOrder(TransportType::Light, 20000, 10, '2026-11-01 00:00:00');
    reportingOrder(TransportType::Light, 30000, 10, '2026-11-05 23:59:59.999999');
    reportingOrder(TransportType::Light, 40000, 10, '2026-11-06 00:00:00');

    $json = $this->getJson(reportingPeriodUrl())->assertOk()->json();

    expect($json['totals']['orders_created'])->toBe(2)
        ->and($json['by_transport'][1]['orders_created'])->toBe(2);
});

it('даёт те же агрегаты при периоде в Europe/Berlin', function (): void {
    reportingDataset();

    $moscow = $this->getJson(reportingPeriodUrl())->assertOk()->json();
    // Те же моменты времени: 2026-11-01T00:00:00+03:00 = 2026-10-31T22:00:00+01:00.
    $berlin = $this->getJson(reportingUrl('2026-10-31T22:00:00+01:00', '2026-11-05T22:00:00+01:00'))
        ->assertOk()
        ->json();

    expect($berlin['totals'])->toBe($moscow['totals'])
        ->and($berlin['by_transport'])->toBe($moscow['by_transport'])
        ->and($berlin['period']['from'])->toBe('2026-10-31T22:00:00+01:00')
        ->and($berlin['period']['to'])->toBe('2026-11-05T22:00:00+01:00');
});

it('отказывает кодом 409 без частичных агрегатов при чужой валюте', function (): void {
    $courier = User::factory()->courier()->create();
    // Заказ создан до периода и вручён в нём: проверка валюты обязана его увидеть.
    $order = reportingOrder(TransportType::Car, 70000, 30, '2026-10-01 10:00:00', null, 'USD');
    reportingDelivery($order, $courier, '2026-11-02 10:00:00');

    $response = $this->getJson(reportingPeriodUrl());

    $response->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'REPORT_CURRENCY_MISMATCH')
        ->assertJsonMissingPath('totals')
        ->assertJsonMissingPath('by_transport');

    $body = (string) $response->getContent();

    expect($body)->not->toContain((string) $order->number)
        ->and($body)->not->toContain((string) $order->tracking_code)
        ->and($body)->not->toContain('"revenue"');
});
