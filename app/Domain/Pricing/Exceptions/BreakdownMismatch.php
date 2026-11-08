<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Pricing\Money;
use DomainException;

/**
 * Сумма строк разбивки не совпала с итоговой суммой.
 */
final class BreakdownMismatch extends DomainException
{
    public static function forTotals(Money $linesTotal, Money $total): self
    {
        return new self(sprintf(
            'Сумма строк разбивки (%s) не совпадает с итогом (%s).',
            $linesTotal->format(),
            $total->format(),
        ));
    }
}
