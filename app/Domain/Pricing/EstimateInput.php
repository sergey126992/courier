<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Identity\Exceptions\InvalidTransportCombination;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use DateTimeImmutable;

/**
 * Проверенные входные данные расчёта.
 */
final readonly class EstimateInput
{
    /**
     * @throws InvalidTransportCombination вид транспорта несовместим с типом
     */
    public function __construct(
        private TransportType $transport,
        private ?LightVehicleKind $lightVehicleKind,
        private Distance $distance,
        private Parcel $parcel,
        private DateTimeImmutable $requestedAt,
        private bool $isPaidParking = false,
    ) {
        $this->transport->assertAccepts($this->lightVehicleKind);
    }

    /**
     * Тип транспорта.
     */
    public function transport(): TransportType
    {
        return $this->transport;
    }

    /**
     * Вид лёгкого транспорта, если он применим.
     */
    public function lightVehicleKind(): ?LightVehicleKind
    {
        return $this->lightVehicleKind;
    }

    /**
     * Расстояние маршрута.
     */
    public function distance(): Distance
    {
        return $this->distance;
    }

    /**
     * Посылка.
     */
    public function parcel(): Parcel
    {
        return $this->parcel;
    }

    /**
     * Момент заявки — по нему определяется час пик.
     */
    public function requestedAt(): DateTimeImmutable
    {
        return $this->requestedAt;
    }

    /**
     * Признак платной парковки в точке доставки.
     */
    public function isPaidParking(): bool
    {
        return $this->isPaidParking;
    }
}
