<?php

declare(strict_types=1);

namespace App\Domain\Tracking;

use App\Domain\Geography\Coordinates;
use DateTimeImmutable;

/**
 * Подготовленная геоточка до записи (FR-TRK-101…106).
 *
 * Объект-значение переносит в операцию уже проверенные координаты, время
 * и источник. Правила порядка времени проверяет TrackingSequence, поэтому
 * сам объект ничего не читает из фреймворка и конфигурации.
 */
final readonly class TrackingPointDraft
{
    public function __construct(
        public Coordinates $coordinates,
        public DateTimeImmutable $recordedAt,
        public TrackingPointSource $source,
    ) {}
}
