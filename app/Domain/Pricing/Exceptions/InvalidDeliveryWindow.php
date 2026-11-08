<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use DomainException;

/**
 * Некорректное окно доставки.
 */
final class InvalidDeliveryWindow extends DomainException
{
    public static function forEtaMinutes(int $etaMinutes): self
    {
        return new self(sprintf('Прогноз доставки обязан быть положительным, получено %d мин.', $etaMinutes));
    }
}
