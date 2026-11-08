<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Exceptions;

use App\Domain\Identity\TransportType;
use DomainException;

/**
 * Расстояние маршрута больше радиуса обслуживания транспорта.
 */
final class ParcelOutsideDeliveryRadius extends DomainException
{
    private function __construct(
        string $message,
        private readonly float $maxRadiusKm,
        private readonly TransportType $transport,
    ) {
        parent::__construct($message);
    }

    public static function forDistance(TransportType $transport, float $maxRadiusKm): self
    {
        return new self(
            sprintf(
                'Адрес вне радиуса обслуживания транспорта «%s»: радиус %s км.',
                $transport->label(),
                $maxRadiusKm,
            ),
            $maxRadiusKm,
            $transport,
        );
    }

    /**
     * Радиус обслуживания транспорта в километрах.
     */
    public function maxRadiusKm(): float
    {
        return $this->maxRadiusKm;
    }

    /**
     * Тип транспорта, для которого радиус превышен.
     */
    public function transport(): TransportType
    {
        return $this->transport;
    }
}
