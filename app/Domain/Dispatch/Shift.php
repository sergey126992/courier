<?php

declare(strict_types=1);

namespace App\Domain\Dispatch;

use App\Domain\Dispatch\Exceptions\CourierShiftNotOpen;
use App\Domain\Dispatch\Exceptions\InvalidShiftPeriod;
use App\Domain\Dispatch\Exceptions\ShiftHasActiveAssignment;
use DateTimeImmutable;

/**
 * Смена курьера как доменное состояние (FR-DSP-101…106).
 *
 * Отдельного статуса нет: смена открыта, пока не задано окончание. Домен не
 * знает о базе и HTTP: время и признак активного назначения приходят
 * аргументами, а вызывающий слой переводит исключения в коды ошибок API.
 */
final readonly class Shift
{
    /**
     * @throws InvalidShiftPeriod окончание не позже начала
     */
    public function __construct(
        public int $courierId,
        public ?int $zoneId,
        public DateTimeImmutable $startedAt,
        public ?DateTimeImmutable $endedAt = null,
    ) {
        if ($this->endedAt !== null && $this->endedAt <= $this->startedAt) {
            throw InvalidShiftPeriod::make($this->startedAt, $this->endedAt);
        }
    }

    /**
     * Новая открытая смена: окончания нет, поэтому смена тянется до закрытия.
     */
    public static function open(int $courierId, ?int $zoneId, DateTimeImmutable $startedAt): self
    {
        return new self($courierId, $zoneId, $startedAt);
    }

    /**
     * Смена ещё не закрыта (FR-DSP-104).
     */
    public function isOpen(): bool
    {
        return $this->endedAt === null;
    }

    /**
     * Пересечение интервалов: открытая смена считается бесконечной (FR-DSP-103).
     *
     * Момент окончания одной смены не пересекается с моментом начала другой.
     */
    public function overlaps(self $other): bool
    {
        $startsBeforeOtherEnds = $other->endedAt === null || $this->startedAt < $other->endedAt;
        $otherStartsBeforeEnds = $this->endedAt === null || $other->startedAt < $this->endedAt;

        return $startsBeforeOtherEnds && $otherStartsBeforeEnds;
    }

    /**
     * Закрытие смены: запрещено для закрытой смены и при активном назначении.
     *
     * @throws CourierShiftNotOpen смена уже закрыта
     * @throws ShiftHasActiveAssignment у курьера есть незавершённое назначение
     * @throws InvalidShiftPeriod окончание не позже начала
     */
    public function close(DateTimeImmutable $endedAt, bool $hasActiveAssignment): self
    {
        if (! $this->isOpen()) {
            throw CourierShiftNotOpen::make($this->courierId);
        }

        if ($hasActiveAssignment) {
            throw ShiftHasActiveAssignment::make($this->courierId);
        }

        return new self($this->courierId, $this->zoneId, $this->startedAt, $endedAt);
    }
}
