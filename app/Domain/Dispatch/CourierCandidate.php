<?php

declare(strict_types=1);

namespace App\Domain\Dispatch;

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;

/**
 * Кандидат на назначение заказа (FR-DSP-111…118).
 *
 * Объект неизменяем и содержит только то, что нужно правилу подбора:
 * кто это, на каком транспорте, как далеко до забора, сколько назначений
 * за смену и сколько займёт подъезд к точке забора.
 *
 * Неизвестная последняя позиция выражается пустым расстоянием и пустым
 * временем подхода: кандидат не исключается, а получает худший ранг (FR-DSP-116).
 */
final readonly class CourierCandidate
{
    /**
     * @param float|null $distanceToPickupKm расстояние до точки забора; null — позиция неизвестна
     * @param int $assignmentsInShift число назначений курьера за текущую смену
     * @param int|null $pickupApproachMinutes время подхода к забору; null — позиция неизвестна
     * @param int $orderEtaMinutes зафиксированный прогноз доставки заказа
     */
    public function __construct(
        private int $courierId,
        private string $name,
        private TransportType $transport,
        private ?LightVehicleKind $lightVehicleKind,
        private ?float $distanceToPickupKm,
        private int $assignmentsInShift,
        private ?int $pickupApproachMinutes,
        private int $orderEtaMinutes,
    ) {}

    /**
     * Идентификатор курьера.
     */
    public function courierId(): int
    {
        return $this->courierId;
    }

    /**
     * Имя курьера для ответа диспетчеру.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Тип транспорта курьера: совпадает с типом транспорта заказа (FR-DSP-113).
     */
    public function transport(): TransportType
    {
        return $this->transport;
    }

    /**
     * Вид лёгкого транспорта; у остальных типов отсутствует.
     */
    public function lightVehicleKind(): ?LightVehicleKind
    {
        return $this->lightVehicleKind;
    }

    /**
     * Расстояние от последней известной позиции до точки забора.
     */
    public function distanceToPickupKm(): ?float
    {
        return $this->distanceToPickupKm;
    }

    /**
     * Сколько назначений у курьера за текущую смену (FR-DSP-123).
     */
    public function assignmentsInShift(): int
    {
        return $this->assignmentsInShift;
    }

    /**
     * Время подхода к точке забора по скорости транспорта и часам пик (FR-DSP-124).
     */
    public function pickupApproachMinutes(): ?int
    {
        return $this->pickupApproachMinutes;
    }

    /**
     * Прогноз доставки заказа — слагаемое правила «лучший ETA».
     */
    public function orderEtaMinutes(): int
    {
        return $this->orderEtaMinutes;
    }

    /**
     * Известна ли позиция курьера: без неё расстояние и время подхода пустые.
     */
    public function hasPosition(): bool
    {
        return $this->distanceToPickupKm !== null;
    }
}
