<?php

declare(strict_types=1);

namespace App\Domain\Geography\Exceptions;

use App\Domain\Geography\Coordinates;
use DomainException;

/**
 * Адрес не попадает ни в одну активную зону обслуживания.
 */
final class AddressOutsideServiceArea extends DomainException
{
    public static function forCoordinates(Coordinates $coordinates): self
    {
        return new self(sprintf(
            'Адрес с координатами %s, %s не попадает ни в одну активную зону обслуживания.',
            $coordinates->latitude(),
            $coordinates->longitude(),
        ));
    }
}
