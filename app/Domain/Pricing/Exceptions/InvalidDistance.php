<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use DomainException;

/**
 * Некорректное расстояние маршрута.
 */
final class InvalidDistance extends DomainException
{
    public static function forKilometers(float $kilometers): self
    {
        return new self(sprintf(
            'Расстояние обязано быть неотрицательным с точностью до 0.1 км, получено %s км.',
            $kilometers,
        ));
    }
}
