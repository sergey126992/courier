<?php

declare(strict_types=1);

namespace App\Domain\Tracking\Exceptions;

use DateTimeImmutable;
use DomainException;

/**
 * Время точки опережает текущее сверх допуска (FR-TRK-104).
 */
final class TrackingPointInFuture extends DomainException
{
    public static function beyondTolerance(
        DateTimeImmutable $recordedAt,
        DateTimeImmutable $now,
        int $toleranceSeconds,
    ): self {
        return new self(sprintf(
            'Время точки %s опережает текущее %s более чем на %d секунд.',
            $recordedAt->format(DATE_ATOM),
            $now->format(DATE_ATOM),
            $toleranceSeconds,
        ));
    }
}
