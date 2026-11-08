<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Pricing\Exceptions\InvalidParcel;

/**
 * Посылка: вес, габариты и заявленная стоимость.
 *
 * Габариты не участвуют в расчёте цены на этом этапе, но входят во входные данные
 * как основа будущих правил объёма.
 */
final readonly class Parcel
{
    public function __construct(
        private int $weightGrams,
        private int $lengthCm,
        private int $widthCm,
        private int $heightCm,
        private ?Money $declaredValue = null,
    ) {
        if ($this->weightGrams <= 0) {
            throw InvalidParcel::forWeight($this->weightGrams);
        }

        $this->assertDimension('длина', $this->lengthCm);
        $this->assertDimension('ширина', $this->widthCm);
        $this->assertDimension('высота', $this->heightCm);
    }

    /**
     * Вес посылки в граммах.
     */
    public function weightGrams(): int
    {
        return $this->weightGrams;
    }

    /**
     * Длина в сантиметрах.
     */
    public function lengthCm(): int
    {
        return $this->lengthCm;
    }

    /**
     * Ширина в сантиметрах.
     */
    public function widthCm(): int
    {
        return $this->widthCm;
    }

    /**
     * Высота в сантиметрах.
     */
    public function heightCm(): int
    {
        return $this->heightCm;
    }

    /**
     * Заявленная стоимость посылки, если указана.
     */
    public function declaredValue(): ?Money
    {
        return $this->declaredValue;
    }

    /**
     * Превышает ли вес посылки заданный предел.
     *
     * Вес, равный пределу, считается допустимым.
     */
    public function exceedsWeight(int $limitGrams): bool
    {
        return $this->weightGrams > $limitGrams;
    }

    /**
     * @throws InvalidParcel габарит не положительный
     */
    private function assertDimension(string $name, int $valueCm): void
    {
        if ($valueCm <= 0) {
            throw InvalidParcel::forDimension($name, $valueCm);
        }
    }
}
