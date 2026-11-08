<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

/**
 * Коды строк разбивки цены.
 */
enum BreakdownCode: string
{
    case Base = 'base';
    case Distance = 'distance';
    case Surge = 'surge';
    case Parking = 'parking';

    /**
     * Человекочитаемое название строки по умолчанию.
     */
    public function label(): string
    {
        return match ($this) {
            self::Base => 'Базовая ставка',
            self::Distance => 'Расстояние',
            self::Surge => 'Надбавка за час пик',
            self::Parking => 'Надбавка за парковку',
        };
    }
}
