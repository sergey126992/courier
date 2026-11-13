<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Pricing\Exceptions\InvalidDistance;

/**
 * Расстояние маршрута в километрах с точностью до сотых.
 */
final readonly class Distance
{
    private function __construct(
        private float $kilometers,
    ) {}

    /**
     * Создаёт расстояние из километров; допускается не более двух знаков после запятой.
     *
     * @throws InvalidDistance расстояние отрицательное или точнее 0.01 км
     */
    #[\NoDiscard]
    public static function fromKilometers(float $kilometers): self
    {
        if ($kilometers < 0 || abs($kilometers - round($kilometers, 2)) > 1e-9) {
            throw InvalidDistance::forKilometers($kilometers);
        }

        // Округление снимает хвост двоичного представления (4.2 → 4.2000000000000002).
        return new self(round($kilometers, 2));
    }

    /**
     * Расстояние в километрах.
     */
    public function kilometers(): float
    {
        return $this->kilometers;
    }

    /**
     * Название для строки разбивки: «4.2 км» или «4.25 км».
     *
     * Хвостовой ноль сотых не печатается, но один знак после запятой остаётся всегда.
     */
    public function label(): string
    {
        $hundredths = (int) round($this->kilometers * 100);
        $decimals = $hundredths % 10 === 0 ? 1 : 2;

        return number_format($this->kilometers, $decimals, '.', '').' км';
    }

    /**
     * Превышает ли расстояние заданный предел.
     *
     * Расстояние, равное пределу, считается допустимым.
     */
    public function exceeds(float $limitKm): bool
    {
        return $this->kilometers > $limitKm;
    }
}
