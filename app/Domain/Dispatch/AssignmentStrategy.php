<?php

declare(strict_types=1);

namespace App\Domain\Dispatch;

/**
 * Правило подбора курьера (FR-DSP-121…127).
 *
 * Правило выбирается конфигурацией или запросом диспетчера, а конкретную
 * реализацию находит компонент выбора. Набор значений фиксируется в строке
 * назначения, поэтому подпись нужна и для чтения истории.
 */
enum AssignmentStrategy: string
{
    case Nearest = 'nearest';
    case LeastLoaded = 'least_loaded';
    case BestEta = 'best_eta';

    /**
     * Человекочитаемое название правила.
     */
    public function label(): string
    {
        return match ($this) {
            self::Nearest => 'Ближайший',
            self::LeastLoaded => 'Наименее загруженный',
            self::BestEta => 'Лучший прогноз прибытия',
        };
    }
}
