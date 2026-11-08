<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use DomainException;

/**
 * Операция над суммами в разных валютах.
 */
final class CurrencyMismatch extends DomainException
{
    public static function forOperation(string $operation, string $left, string $right): self
    {
        return new self(sprintf(
            'Операция «%s» невозможна: валюты сумм различаются (%s и %s).',
            $operation,
            $left,
            $right,
        ));
    }
}
