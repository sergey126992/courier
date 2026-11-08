<?php

declare(strict_types=1);

namespace App\Domain\Geography\Exceptions;

use DomainException;

/**
 * Некорректные географические координаты.
 */
final class InvalidCoordinates extends DomainException
{
    public static function forLatitude(float $latitude): self
    {
        return new self(sprintf('Широта обязана быть в диапазоне от -90 до 90, получено %s.', $latitude));
    }

    public static function forLongitude(float $longitude): self
    {
        return new self(sprintf('Долгота обязана быть в диапазоне от -180 до 180, получено %s.', $longitude));
    }

    public static function forPrecision(string $name, float $value): self
    {
        return new self(sprintf(
            'Значение «%s» обязано иметь не более шести знаков после запятой, получено %s.',
            $name,
            $value,
        ));
    }
}
