<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use DomainException;

/**
 * Некорректный код трекинга.
 */
final class InvalidTrackingCode extends DomainException
{
    public static function forFormat(string $value): self
    {
        return new self(sprintf(
            'Код трекинга обязан иметь вид trk_ и 8–32 символа в нижнем регистре, получено «%s».',
            $value,
        ));
    }
}
