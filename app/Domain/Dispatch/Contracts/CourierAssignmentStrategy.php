<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Contracts;

use App\Domain\Dispatch\AssignmentStrategy;
use App\Domain\Dispatch\CourierCandidate;

/**
 * Контракт правила подбора курьера (FR-DSP-121…127).
 *
 * Правило только упорядочивает готовый набор кандидатов: фильтры доступности
 * и совместимости выполняет отбор, поэтому новое правило — отдельный класс
 * без правок вызывающего кода (FR-DSP-126).
 *
 * Форма выбрана так, чтобы одно правило отвечало и на «кто первый», и на
 * «в каком порядке показать список»: победитель — первый элемент результата.
 */
interface CourierAssignmentStrategy
{
    /**
     * Правило, которое реализует класс: по нему стратегия находится в наборе.
     */
    public function strategy(): AssignmentStrategy;

    /**
     * Упорядочивает кандидатов по правилу с детерминированным разрешением ничьей.
     *
     * Порядок не зависит от исходного порядка набора и от одинаковых запросов:
     * сначала метрика правила, затем число назначений за смену, затем идентификатор.
     *
     * @param list<CourierCandidate> $candidates
     * @return list<CourierCandidate> тот же набор в порядке предпочтения
     */
    public function rank(array $candidates): array;
}
