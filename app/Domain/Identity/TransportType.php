<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Тип транспорта курьера.
 */
enum TransportType: string
{
    case OnFoot = 'on_foot';
    case Light = 'light';
    case Car = 'car';

    /**
     * Человекочитаемое название типа транспорта для интерфейса.
     */
    public function label(): string
    {
        return match ($this) {
            self::OnFoot => 'Пешком',
            self::Light => 'Лёгкий транспорт',
            self::Car => 'Автомобиль',
        };
    }

    /**
     * Лёгкий транспорт — единственный тип, для которого задаётся вид.
     */
    public function isLight(): bool
    {
        return $this === self::Light;
    }

    /**
     * Нужен ли курьеру автомобиль (а не лёгкий транспорт или пеший ход).
     */
    public function requiresVehicle(): bool
    {
        return match ($this) {
            self::OnFoot, self::Light => false,
            self::Car => true,
        };
    }

    /**
     * Проверяет допустимость вида лёгкого транспорта для этого типа.
     *
     * Вид лёгкого транспорта допустим только при типе «лёгкий транспорт».
     *
     * @throws Exceptions\InvalidTransportCombination вид задан для типа, отличного от лёгкого
     */
    public function assertAccepts(?LightVehicleKind $kind): void
    {
        if ($kind !== null && ! $this->isLight()) {
            throw Exceptions\InvalidTransportCombination::forKind($this, $kind);
        }
    }
}
