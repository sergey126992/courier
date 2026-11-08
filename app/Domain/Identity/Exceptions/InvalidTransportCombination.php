<?php

declare(strict_types=1);

namespace App\Domain\Identity\Exceptions;

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use DomainException;

/**
 * Вид лёгкого транспорта задан для типа транспорта, отличного от лёгкого.
 */
final class InvalidTransportCombination extends DomainException
{
    public static function forKind(TransportType $type, LightVehicleKind $kind): self
    {
        return new self(sprintf(
            'Вид лёгкого транспорта «%s» недопустим для типа транспорта «%s».',
            $kind->label(),
            $type->label(),
        ));
    }
}
