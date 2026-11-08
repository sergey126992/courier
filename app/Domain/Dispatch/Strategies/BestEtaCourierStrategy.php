<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Strategies;

use App\Domain\Dispatch\AssignmentStrategy;
use App\Domain\Dispatch\CourierCandidate;

/**
 * Правило «лучший ETA»: минимум суммы подхода к забору и прогноза заказа (FR-DSP-124).
 *
 * Оба слагаемых посчитаны до правила: подход — по последней позиции и скорости
 * транспорта с учётом часов пик, прогноз заказа — зафиксирован при создании.
 * Без известной позиции сумма не определена, и кандидат оказывается последним.
 */
final class BestEtaCourierStrategy extends OrderedCourierStrategy
{
    public function strategy(): AssignmentStrategy
    {
        return AssignmentStrategy::BestEta;
    }

    protected function metric(CourierCandidate $candidate): ?float
    {
        $approach = $candidate->pickupApproachMinutes();

        return $approach === null
            ? null
            : (float) ($approach + $candidate->orderEtaMinutes());
    }
}
