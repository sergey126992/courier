<?php

declare(strict_types=1);

namespace App\Domain\Dispatch;

use App\Domain\Dispatch\Contracts\CourierAssignmentStrategy;
use App\Domain\Dispatch\Exceptions\UnknownAssignmentStrategy;

/**
 * Выбор правила подбора по имени из переданного набора (FR-DSP-121, 125).
 *
 * Набор стратегий приходит снаружи, поэтому новое правило подключается
 * регистрацией, а не правкой этого класса и вызывающего кода (FR-DSP-126).
 * Имя правила — значение AssignmentStrategy: одно и то же имя используется
 * в конфигурации, в запросе и в строке назначения.
 */
final readonly class CourierSelection
{
    /**
     * @var array<string, CourierAssignmentStrategy>
     */
    private array $strategies;

    /**
     * @param iterable<CourierAssignmentStrategy> $strategies набор правил подбора
     */
    public function __construct(iterable $strategies)
    {
        $indexed = [];

        foreach ($strategies as $strategy) {
            $indexed[$strategy->strategy()->value] = $strategy;
        }

        $this->strategies = $indexed;
    }

    /**
     * Правило по имени; неизвестное имя — доменная ошибка, а не запасной вариант.
     *
     * @throws UnknownAssignmentStrategy правило с таким именем не зарегистрировано
     */
    public function forName(string $name): CourierAssignmentStrategy
    {
        return $this->strategies[$name] ?? throw UnknownAssignmentStrategy::named($name);
    }

    /**
     * Имена зарегистрированных правил: нужны проверкам настройки и тестам.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->strategies);
    }
}
