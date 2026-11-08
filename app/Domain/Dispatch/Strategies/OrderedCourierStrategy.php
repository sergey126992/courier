<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Strategies;

use App\Domain\Dispatch\Contracts\CourierAssignmentStrategy;
use App\Domain\Dispatch\CourierCandidate;

/**
 * Общая механика правил подбора: метрика правила и детерминированная ничья.
 *
 * Наследник задаёт только метрику, а порядок сравнения одинаков для всех
 * правил (FR-DSP-127): меньше метрика — выше; при равенстве — меньше назначений
 * за смену; при равенстве и здесь — меньше идентификатор курьера.
 * Кандидат без известной позиции имеет пустую метрику и оказывается в конце.
 */
abstract class OrderedCourierStrategy implements CourierAssignmentStrategy
{
    /**
     * Эквивалент бесконечности для пустой метрики: такой кандидат всегда последний.
     */
    private const NO_METRIC = INF;

    final public function rank(array $candidates): array
    {
        $ranked = array_values($candidates);

        usort($ranked, $this->compare(...));

        return $ranked;
    }

    /**
     * Значение метрики правила; null — метрика не определена (нет позиции курьера).
     */
    abstract protected function metric(CourierCandidate $candidate): ?float;

    /**
     * Сравнение двух кандидатов: метрика правила, затем загрузка, затем идентификатор.
     */
    private function compare(CourierCandidate $left, CourierCandidate $right): int
    {
        $byMetric = ($this->metric($left) ?? self::NO_METRIC) <=> ($this->metric($right) ?? self::NO_METRIC);

        if ($byMetric !== 0) {
            return $byMetric;
        }

        return $left->assignmentsInShift() <=> $right->assignmentsInShift()
            ?: $left->courierId() <=> $right->courierId();
    }
}
