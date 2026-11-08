<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Вид лёгкого транспорта курьера.
 */
enum LightVehicleKind: string
{
    case Bicycle = 'bicycle';
    case Scooter = 'scooter';
    case EScooter = 'e_scooter';
    case EBike = 'e_bike';

    /**
     * Человекочитаемое название вида лёгкого транспорта для интерфейса.
     */
    public function label(): string
    {
        return match ($this) {
            self::Bicycle => 'Велосипед',
            self::Scooter => 'Самокат',
            self::EScooter => 'Электросамокат',
            self::EBike => 'Электровелосипед',
        };
    }
}
