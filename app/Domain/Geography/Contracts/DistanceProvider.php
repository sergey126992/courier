<?php

declare(strict_types=1);

namespace App\Domain\Geography\Contracts;

use App\Domain\Geography\Coordinates;
use App\Domain\Pricing\Distance;

/**
 * Порт определения расстояния между двумя координатами.
 *
 * Домен не знает, как именно считается расстояние: по прямой, по маршруту
 * или через внешний сервис.
 */
interface DistanceProvider
{
    public function between(Coordinates $from, Coordinates $to): Distance;
}
