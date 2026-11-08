<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Exceptions;

use DateTimeImmutable;
use DateTimeInterface;
use DomainException;

/**
 * Окончание смены обязано быть позже её начала (FR-DSP-104).
 */
final class InvalidShiftPeriod extends DomainException
{
    public static function make(DateTimeImmutable $startedAt, DateTimeImmutable $endedAt): self
    {
        return new self(sprintf(
            'Окончание смены %s должно быть позже начала %s.',
            $endedAt->format(DateTimeInterface::ATOM),
            $startedAt->format(DateTimeInterface::ATOM),
        ));
    }
}
