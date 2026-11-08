<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Strategies;

use App\Domain\Dispatch\AssignmentStrategy;
use App\Domain\Dispatch\CourierCandidate;

/**
 * Правило «ближайший»: выигрывает минимальное расстояние до точки забора (FR-DSP-122).
 *
 * Расстояние считается отбором через провайдер расстояний и передаётся
 * кандидатом: правило само к провайдеру не обращается.
 */
final class NearestCourierStrategy extends OrderedCourierStrategy
{
    public function strategy(): AssignmentStrategy
    {
        return AssignmentStrategy::Nearest;
    }

    protected function metric(CourierCandidate $candidate): ?float
    {
        return $candidate->distanceToPickupKm();
    }
}
