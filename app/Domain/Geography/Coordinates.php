<?php

declare(strict_types=1);

namespace App\Domain\Geography;

use App\Domain\Geography\Exceptions\InvalidCoordinates;

/**
 * Географические координаты точки в десятичных градусах.
 *
 * Правила: широта от -90 до 90, долгота от -180 до 180, точность —
 * не более шести знаков после запятой (примерно 0,1 м). Расстояние между
 * точками координаты не считают: за это отвечает провайдер расстояний.
 */
final readonly class Coordinates
{
    /**
     * Максимальное число знаков после запятой в координате.
     */
    private const MAX_DECIMALS = 6;

    /**
     * Допуск сравнения при проверке точности и равенства значений.
     */
    private const EPSILON = 1e-9;

    /**
     * @throws InvalidCoordinates широта или долгота вне диапазона либо точнее шести знаков
     */
    public function __construct(
        private float $latitude,
        private float $longitude,
    ) {
        if ($this->latitude < -90.0 || $this->latitude > 90.0) {
            throw InvalidCoordinates::forLatitude($this->latitude);
        }

        if ($this->longitude < -180.0 || $this->longitude > 180.0) {
            throw InvalidCoordinates::forLongitude($this->longitude);
        }

        $this->assertPrecision('широта', $this->latitude);
        $this->assertPrecision('долгота', $this->longitude);
    }

    /**
     * Широта в градусах.
     */
    public function latitude(): float
    {
        return $this->latitude;
    }

    /**
     * Долгота в градусах.
     */
    public function longitude(): float
    {
        return $this->longitude;
    }

    /**
     * Совпадают ли координаты по значению.
     */
    public function equals(self $other): bool
    {
        return abs($this->latitude - $other->latitude) < self::EPSILON
            && abs($this->longitude - $other->longitude) < self::EPSILON;
    }

    /**
     * Представление для хранения и обмена: шесть знаков после запятой.
     *
     * Ключи совпадают с именами полей адреса в API (latitude/longitude),
     * поэтому одна форма координат используется и в адресах, и в границах зон.
     *
     * @return array{latitude: float, longitude: float}
     */
    public function toArray(): array
    {
        return [
            'latitude' => round($this->latitude, self::MAX_DECIMALS),
            'longitude' => round($this->longitude, self::MAX_DECIMALS),
        ];
    }

    /**
     * @throws InvalidCoordinates значение точнее шести знаков после запятой
     */
    private function assertPrecision(string $name, float $value): void
    {
        if (abs($value - round($value, self::MAX_DECIMALS)) > self::EPSILON) {
            throw InvalidCoordinates::forPrecision($name, $value);
        }
    }
}
