<?php

declare(strict_types=1);

namespace App\Domain\Tracking;

/**
 * Источник геоточки (FR-TRK-106).
 *
 * На этапе M5–M6 значение одно — точка от курьера. Набор расширяется новым
 * случаем перечисления и миграцией ограничения таблицы; других источников
 * (импорт, ручная правка диспетчером) в схеме нет намеренно.
 */
enum TrackingPointSource: string
{
    case Courier = 'courier';

    /**
     * Человекочитаемое название источника.
     */
    public function label(): string
    {
        return match ($this) {
            self::Courier => 'Курьер',
        };
    }

    /**
     * Значения источников для ограничения таблицы.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $source): string => $source->value, self::cases());
    }
}
