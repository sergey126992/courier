<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Exceptions;

use DateTimeImmutable;
use DomainException;

/**
 * Время точки не больше времени предыдущей точки назначения (FR-TRK-105).
 */
final class TrackingPointOutOfSequence extends DomainException
{
    public static function notAfter(DateTimeImmutable $recordedAt, DateTimeImmutable $previous): self
    {
        return new self(sprintf(
            'Время точки %s должно быть строго позже предыдущей точки %s.',
            $recordedAt->format(DATE_ATOM),
            $previous->format(DATE_ATOM),
        ));
    }
}
