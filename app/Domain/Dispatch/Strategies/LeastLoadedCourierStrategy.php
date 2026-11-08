<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Strategies;

use App\Domain\Dispatch\AssignmentStrategy;
use App\Domain\Dispatch\CourierCandidate;

/**
 * Правило «наименее загруженный»: выигрывает минимум назначений за смену (FR-DSP-123).
 *
 * Активное назначение у курьера одно, поэтому загрузка — число назначений,
 * сделанных с начала текущей смены.
 */
final class LeastLoadedCourierStrategy extends OrderedCourierStrategy
{
    public function strategy(): AssignmentStrategy
    {
        return AssignmentStrategy::LeastLoaded;
    }

    protected function metric(CourierCandidate $candidate): float
    {
        return (float) $candidate->assignmentsInShift();
    }
}
