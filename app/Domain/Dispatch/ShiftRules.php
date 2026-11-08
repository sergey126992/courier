<?php

declare(strict_types=1);

namespace App\Domain\Dispatch;

use App\Domain\Dispatch\Exceptions\CourierInactive;
use App\Domain\Dispatch\Exceptions\CourierShiftAlreadyOpen;
use App\Domain\Dispatch\Exceptions\CourierShiftOverlap;

/**
 * Правила открытия смены относительно уже существующих смен курьера.
 *
 * Проверка пересечения есть и в домене, и в ограничении базы: домен даёт
 * клиенту понятный код ошибки, база страхует от гонки двух одновременных
 * запросов (FR-DSP-103). Список смен передаёт вызывающий слой.
 */
final class ShiftRules
{
    /**
     * @param list<Shift> $existing смены курьера, которые могут пересечься с новой
     *
     * @throws CourierInactive профиль курьера неактивен
     * @throws CourierShiftAlreadyOpen у курьера уже есть открытая смена
     * @throws CourierShiftOverlap новая смена пересекается с существующей
     */
    public static function assertCanOpen(bool $courierActive, array $existing, Shift $candidate): void
    {
        if (! $courierActive) {
            throw CourierInactive::make($candidate->courierId);
        }

        foreach ($existing as $shift) {
            // Открытая смена пересекается с любой новой, поэтому её код важнее.
            if ($shift->isOpen()) {
                throw CourierShiftAlreadyOpen::make($candidate->courierId);
            }
        }

        foreach ($existing as $shift) {
            if ($shift->overlaps($candidate)) {
                throw CourierShiftOverlap::make($candidate->courierId, $shift->startedAt, $shift->endedAt);
            }
        }
    }
}
