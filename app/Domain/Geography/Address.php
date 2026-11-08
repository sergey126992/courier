<?php

declare(strict_types=1);

namespace App\Domain\Geography;

use App\Domain\Geography\Exceptions\InvalidAddress;

/**
 * Адрес доставки: район, улица, дом и координаты точки.
 *
 * Сравнение адресов — по значению: все поля и координаты обязаны совпасть.
 */
final readonly class Address
{
    private string $district;

    private string $street;

    private string $house;

    /**
     * @throws InvalidAddress обязательное поле пустое
     */
    public function __construct(
        string $district,
        string $street,
        string $house,
        private Coordinates $coordinates,
    ) {
        $this->district = self::requireFilled('район', $district);
        $this->street = self::requireFilled('улица', $street);
        $this->house = self::requireFilled('дом', $house);
    }

    /**
     * Район.
     */
    public function district(): string
    {
        return $this->district;
    }

    /**
     * Улица.
     */
    public function street(): string
    {
        return $this->street;
    }

    /**
     * Дом.
     */
    public function house(): string
    {
        return $this->house;
    }

    /**
     * Координаты адреса.
     */
    public function coordinates(): Coordinates
    {
        return $this->coordinates;
    }

    /**
     * Совпадают ли адреса по значению.
     */
    public function equals(self $other): bool
    {
        return $this->district === $other->district
            && $this->street === $other->street
            && $this->house === $other->house
            && $this->coordinates->equals($other->coordinates);
    }

    /**
     * @throws InvalidAddress значение пустое или состоит из пробелов
     */
    private static function requireFilled(string $field, string $value): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw InvalidAddress::forField($field);
        }

        return $trimmed;
    }
}
