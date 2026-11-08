<?php

declare(strict_types=1);

namespace App\Services\Geography;

use App\Domain\Geography\Contracts\DistanceProvider;
use App\Domain\Geography\Coordinates;
use App\Domain\Pricing\Distance;
use InvalidArgumentException;

/**
 * Локальный провайдер расстояний: прямая по формуле гаверсинуса с поправкой
 * на удлинение маршрута.
 *
 * Коэффициент удлинения компенсирует то, что реальный маршрут длиннее прямой.
 * Значение приходит из конфигурации: домен о способе расчёта не знает.
 */
final readonly class HaversineDistanceProvider implements DistanceProvider
{
    /**
     * Средний радиус Земли в километрах.
     */
    private const EARTH_RADIUS_KM = 6371.0088;

    /**
     * @throws InvalidArgumentException коэффициент удлинения не положительный
     */
    public function __construct(
        private float $routeElongationFactor,
    ) {
        if ($this->routeElongationFactor <= 0.0) {
            throw new InvalidArgumentException(sprintf(
                'Коэффициент удлинения маршрута обязан быть положительным, получено %s.',
                $this->routeElongationFactor,
            ));
        }
    }

    public function between(Coordinates $from, Coordinates $to): Distance
    {
        $fromLatitude = deg2rad($from->latitude());
        $toLatitude = deg2rad($to->latitude());
        $latitudeDelta = $toLatitude - $fromLatitude;
        $longitudeDelta = deg2rad($to->longitude() - $from->longitude());

        $haversine = sin($latitudeDelta / 2) ** 2
            + cos($fromLatitude) * cos($toLatitude) * sin($longitudeDelta / 2) ** 2;

        $centralAngle = 2 * asin(min(1.0, sqrt($haversine)));
        $kilometers = self::EARTH_RADIUS_KM * $centralAngle * $this->routeElongationFactor;

        return Distance::fromKilometers(round($kilometers, 2));
    }
}
