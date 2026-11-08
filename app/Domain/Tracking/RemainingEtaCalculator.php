<?php

declare(strict_types=1);

namespace App\Domain\Tracking;

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Pricing\Distance;
use App\Domain\Pricing\TransportParams;
use DateTimeImmutable;

/**
 * Остаток пути по расстоянию, параметрам транспорта и моменту расчёта.
 *
 * Правило FR-TRK-122: время движения — расстояние делить на скорость
 * транспорта с учётом часа пик, плюс время обработки; результат округляется
 * вверх до минуты. Домен не считает расстояние и не читает конфигурацию:
 * и то, и другое приходит аргументами.
 */
final readonly class RemainingEtaCalculator
{
    /**
     * Скорость ноль или меньше означает отсутствие расчёта, а не нулевой остаток.
     */
    public function calculate(
        Distance $distance,
        TransportParams $params,
        ?LightVehicleKind $kind,
        DateTimeImmutable $at,
    ): RemainingEta {
        $speed = $params->speedKmH($kind, $params->isPeakHour($at));

        if ($speed <= 0.0) {
            return RemainingEta::planned();
        }

        $minutes = $distance->kilometers() / $speed * 60.0 + $params->handlingMinutes();

        // Округление вверх: остаток не занижается даже на доли минуты.
        return RemainingEta::computed((int) ceil($minutes));
    }
}
