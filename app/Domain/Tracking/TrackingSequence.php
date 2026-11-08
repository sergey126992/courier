<?php

declare(strict_types=1);

namespace App\Domain\Tracking;

use App\Domain\Tracking\Exceptions\TrackingPointInFuture;
use App\Domain\Tracking\Exceptions\TrackingPointOutOfSequence;
use DateTimeImmutable;

/**
 * Правила времени геоточки назначения (FR-TRK-104, FR-TRK-105).
 *
 * В пределах одного назначения время точек строго возрастает: точка с тем же
 * или более ранним временем отклоняется. Время из будущего допускается лишь
 * на величину допуска — она приходит аргументом, потому что значение живёт
 * в конфигурации приложения, а домен её не читает.
 */
final class TrackingSequence
{
    /**
     * @param int $futureToleranceSeconds допуск времени точки в будущем в секундах
     *
     * @throws TrackingPointInFuture время точки опережает текущее сверх допуска
     * @throws TrackingPointOutOfSequence время точки не больше предыдущей
     */
    public static function assertAccepts(
        DateTimeImmutable $recordedAt,
        ?DateTimeImmutable $previousRecordedAt,
        DateTimeImmutable $now,
        int $futureToleranceSeconds,
    ): void {
        if ($recordedAt > $now->modify(sprintf('+%d seconds', $futureToleranceSeconds))) {
            throw TrackingPointInFuture::beyondTolerance($recordedAt, $now, $futureToleranceSeconds);
        }

        if ($previousRecordedAt !== null && $recordedAt <= $previousRecordedAt) {
            throw TrackingPointOutOfSequence::notAfter($recordedAt, $previousRecordedAt);
        }
    }
}
