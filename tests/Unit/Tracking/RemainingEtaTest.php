<?php

declare(strict_types=1);

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Pricing\Distance;
use App\Domain\Pricing\Money;
use App\Domain\Pricing\PeakHourSchedule;
use App\Domain\Pricing\TransportParams;
use App\Domain\Tracking\EtaSource;
use App\Domain\Tracking\RemainingEtaCalculator;

/**
 * Параметры расчёта с заданными скоростью, временем обработки и часом пик.
 *
 * @param array<string, float> $speedsByKind
 */
function remainingEtaParams(
    float $speedKmH,
    int $handlingMinutes = 5,
    ?float $peakSpeedKmH = null,
    array $speedsByKind = [],
): TransportParams {
    return new TransportParams(
        maxWeightGrams: 15000,
        maxRadiusKm: 7.0,
        baseRate: Money::rub(14900),
        ratePerKm: Money::rub(2500),
        handlingMinutes: $handlingMinutes,
        speedKmH: $speedKmH,
        peakHours: new PeakHourSchedule([['from' => '07:00', 'to' => '10:00']], 'Europe/Moscow'),
        speedsByKind: $speedsByKind,
        peakSpeedKmH: $peakSpeedKmH,
    );
}

/**
 * Момент расчёта в часовом поясе приложения.
 */
function remainingEtaMoment(string $moment): DateTimeImmutable
{
    return new DateTimeImmutable($moment, new DateTimeZone('Europe/Moscow'));
}

it('считает остаток по скорости транспорта и времени обработки', function (): void {
    $eta = (new RemainingEtaCalculator)->calculate(
        Distance::fromKilometers(4.5),
        remainingEtaParams(speedKmH: 15.0, handlingMinutes: 5),
        LightVehicleKind::Bicycle,
        remainingEtaMoment('2026-05-12 12:00:00'),
    );

    // 4.5 км / 15 км/ч = 18 минут движения плюс 5 минут обработки.
    expect($eta->source())->toBe(EtaSource::Computed)
        ->and($eta->isComputed())->toBeTrue()
        ->and($eta->minutes())->toBe(23);
});

it('округляет остаток вверх до минуты', function (): void {
    $eta = (new RemainingEtaCalculator)->calculate(
        Distance::fromKilometers(2.0),
        remainingEtaParams(speedKmH: 18.0, handlingMinutes: 0),
        null,
        remainingEtaMoment('2026-05-12 12:00:00'),
    );

    // 2 км / 18 км/ч = 6.67 минуты: остаток не занижается, округление вверх.
    expect($eta->minutes())->toBe(7);
});

it('учитывает час пик', function (): void {
    $params = remainingEtaParams(speedKmH: 25.0, handlingMinutes: 5, peakSpeedKmH: 18.0);
    $calculator = new RemainingEtaCalculator;
    $distance = Distance::fromKilometers(4.5);

    $peak = $calculator->calculate($distance, $params, null, remainingEtaMoment('2026-05-12 08:30:00'));
    $offPeak = $calculator->calculate($distance, $params, null, remainingEtaMoment('2026-05-12 12:00:00'));

    // 4.5 / 18 * 60 = 15 минут в час пик против 10.8 минуты вне его; обработка 5.
    expect($peak->minutes())->toBe(20)
        ->and($offPeak->minutes())->toBe(16);
});

it('не считает остаток при нулевой скорости', function (): void {
    $eta = (new RemainingEtaCalculator)->calculate(
        Distance::fromKilometers(4.5),
        remainingEtaParams(speedKmH: 0.0),
        LightVehicleKind::Bicycle,
        remainingEtaMoment('2026-05-12 12:00:00'),
    );

    expect($eta->source())->toBe(EtaSource::Planned)
        ->and($eta->isComputed())->toBeFalse()
        ->and($eta->minutes())->toBeNull();
});
