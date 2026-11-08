<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Exceptions;

use DateTimeImmutable;
use DateTimeInterface;
use DomainException;

/**
 * Новая смена пересекается с существующей сменой курьера (FR-DSP-103).
 */
final class CourierShiftOverlap extends DomainException
{
    public static function make(int $courierId, DateTimeImmutable $startedAt, ?DateTimeImmutable $endedAt): self
    {
        return new self(sprintf(
            'Новая смена курьера %d пересекается со сменой %s — %s.',
            $courierId,
            $startedAt->format(DateTimeInterface::ATOM),
            $endedAt?->format(DateTimeInterface::ATOM) ?? 'без окончания',
        ));
    }
}
