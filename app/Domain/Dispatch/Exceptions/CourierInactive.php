<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Exceptions;

use DomainException;

/**
 * Курьер с неактивным профилем не открывает смену (FR-DSP-105).
 */
final class CourierInactive extends DomainException
{
    public static function make(int $courierId): self
    {
        return new self(sprintf('Курьер %d неактивен и не может открыть смену.', $courierId));
    }
}
