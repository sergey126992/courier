<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use DomainException;

/**
 * Некорректные характеристики посылки.
 */
final class InvalidParcel extends DomainException
{
    public static function forWeight(int $weightGrams): self
    {
        return new self(sprintf('Вес посылки обязан быть положительным, получено %d г.', $weightGrams));
    }

    public static function forDimension(string $dimension, int $valueCm): self
    {
        return new self(sprintf('Габарит «%s» обязан быть положительным, получено %d см.', $dimension, $valueCm));
    }
}
