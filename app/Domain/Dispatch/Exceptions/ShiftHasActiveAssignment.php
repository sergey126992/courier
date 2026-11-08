<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Exceptions;

use DomainException;

/**
 * Смена не закрывается, пока у курьера есть активное назначение (FR-DSP-106).
 */
final class ShiftHasActiveAssignment extends DomainException
{
    public static function make(int $courierId): self
    {
        return new self(sprintf('У курьера %d есть активное назначение.', $courierId));
    }
}
