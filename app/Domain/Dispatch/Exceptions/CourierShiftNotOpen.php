<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Exceptions;

use DomainException;

/**
 * Смена не открыта: закрывать нечего или смена уже закрыта (FR-DSP-104).
 */
final class CourierShiftNotOpen extends DomainException
{
    public static function make(int $courierId): self
    {
        return new self(sprintf('У курьера %d нет открытой смены.', $courierId));
    }
}
