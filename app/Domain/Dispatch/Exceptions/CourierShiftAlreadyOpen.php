<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Exceptions;

use DomainException;

/**
 * У курьера уже есть открытая смена (FR-DSP-102).
 */
final class CourierShiftAlreadyOpen extends DomainException
{
    public static function make(int $courierId): self
    {
        return new self(sprintf('У курьера %d уже есть открытая смена.', $courierId));
    }
}
