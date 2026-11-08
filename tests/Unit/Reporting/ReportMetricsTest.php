<?php

declare(strict_types=1);

use App\Domain\Identity\TransportType;
use App\Domain\Reporting\ReportMetrics;
use App\Domain\Reporting\Rounding;
use App\Domain\Reporting\TransportBreakdown;
use App\Domain\Reporting\TransportBreakdownRow;

it('оставляет количества и суммы нулями при отсутствии данных', function (): void {
    $metrics = new ReportMetrics;

    expect($metrics->ordersCreated)->toBe(0)
        ->and($metrics->ordersCancelled)->toBe(0)
        ->and($metrics->ordersDelivered)->toBe(0)
        ->and($metrics->revenue)->toBe(0)
        ->and($metrics->activeCouriers)->toBe(0)
        ->and($metrics->shiftSeconds)->toBe(0);
});

it('возвращает null для средних и производных при нулевом знаменателе', function (): void {
    $metrics = new ReportMetrics;

    expect($metrics->averageCheck())->toBeNull()
        ->and($metrics->averageEtaMinutes())->toBeNull()
        ->and($metrics->deliveriesPerCourier())->toBeNull();
});

it('округляет средний чек половину вверх', function (int $revenue, int $delivered, int $expected): void {
    $metrics = new ReportMetrics(ordersDelivered: $delivered, revenue: $revenue);

    expect($metrics->averageCheck())->toBe($expected);
})->with([
    'ровно половина вверх' => [101, 2, 51],
    'чуть меньше половины вниз' => [100, 3, 33],
    'точное деление' => [1000, 4, 250],
]);

it('округляет среднее ETA половину вверх до одного знака', function (): void {
    $metrics = new ReportMetrics(ordersCreated: 4, etaMinutesTotal: 5);

    expect($metrics->averageEtaMinutes())->toBe(1.3);
});

it('округляет доставки на курьера половину вверх до двух знаков', function (): void {
    $metrics = new ReportMetrics(ordersDelivered: 1, activeCouriers: 8);

    expect($metrics->deliveriesPerCourier())->toBe(0.13);
});

it('переводит секунды смен в часы с одним знаком', function (): void {
    $metrics = new ReportMetrics(shiftSeconds: 3150);

    expect($metrics->shiftHours())->toBe(0.9);
});

it('округляет значение до целого, одного и двух знаков половину вверх', function (): void {
    expect(Rounding::toInteger(2.5))->toBe(3)
        ->and(Rounding::toInteger(2.4))->toBe(2)
        ->and(Rounding::toOneDecimal(1.25))->toBe(1.3)
        ->and(Rounding::toTwoDecimals(0.125))->toBe(0.13);
});

it('всегда содержит три строки разреза в доменном порядке', function (): void {
    $breakdown = TransportBreakdown::fromRows([
        new TransportBreakdownRow(TransportType::Car, 3, 2, 100, 100.0, 1),
    ]);

    $rows = $breakdown->rows();

    expect($rows)->toHaveCount(3)
        ->and(array_map(static fn (TransportBreakdownRow $row): TransportType => $row->transportType, $rows))
        ->toBe(TransportType::cases());
});

it('отдаёт отсутствующие типы нулями, нулевой долей и первым местом', function (): void {
    $breakdown = TransportBreakdown::fromRows([
        new TransportBreakdownRow(TransportType::Car, 3, 2, 100, 100.0, 1),
    ]);

    $onFoot = $breakdown->rows()[0];

    expect($onFoot->transportType)->toBe(TransportType::OnFoot)
        ->and($onFoot->ordersCreated)->toBe(0)
        ->and($onFoot->ordersDelivered)->toBe(0)
        ->and($onFoot->revenue)->toBe(0)
        ->and($onFoot->sharePercent)->toBe(0.0)
        ->and($onFoot->rank)->toBe(1);
});

it('отдаёт доли и места строк как пришли из SQL', function (): void {
    // Значения приходят из оконных функций запроса, домен их не пересчитывает.
    $breakdown = TransportBreakdown::fromRows([
        new TransportBreakdownRow(TransportType::Car, 2, 1, 90000, 50.0, 1),
        new TransportBreakdownRow(TransportType::OnFoot, 1, 2, 45000, 25.0, 2),
        new TransportBreakdownRow(TransportType::Light, 2, 1, 45000, 25.0, 2),
    ]);

    $rows = $breakdown->rows();

    expect($rows[0]->transportType)->toBe(TransportType::OnFoot)
        ->and($rows[0]->sharePercent)->toBe(25.0)
        ->and($rows[0]->rank)->toBe(2)
        ->and($rows[1]->transportType)->toBe(TransportType::Light)
        ->and($rows[1]->sharePercent)->toBe(25.0)
        ->and($rows[1]->rank)->toBe(2)
        ->and($rows[2]->transportType)->toBe(TransportType::Car)
        ->and($rows[2]->sharePercent)->toBe(50.0)
        ->and($rows[2]->rank)->toBe(1);
});

it('держит доменный порядок строк независимо от порядка входа и выручки', function (): void {
    $breakdown = TransportBreakdown::fromRows([
        new TransportBreakdownRow(TransportType::Car, 2, 1, 500),
        new TransportBreakdownRow(TransportType::OnFoot, 2, 2, 900),
        new TransportBreakdownRow(TransportType::Light, 1, 1, 100),
    ]);

    expect(array_map(
        static fn (TransportBreakdownRow $row): TransportType => $row->transportType,
        $breakdown->rows(),
    ))->toBe([TransportType::OnFoot, TransportType::Light, TransportType::Car]);
});

it('остаётся утилитой без экземпляров', function (): void {
    $reflection = new ReflectionClass(Rounding::class);
    $constructor = $reflection->getConstructor();
    expect($constructor)->not->toBeNull();
    $constructor?->setAccessible(true);

    $instance = $reflection->newInstanceWithoutConstructor();
    $constructor?->invoke($instance);

    expect($instance)->toBeInstanceOf(Rounding::class);
});
