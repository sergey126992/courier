<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Geography\Address;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Order\Exceptions\OrderStatusTransitionNotAllowed;
use App\Domain\Pricing\BreakdownLine;
use App\Domain\Pricing\Distance;
use App\Domain\Pricing\Money;
use App\Domain\Pricing\Parcel;
use App\Domain\Pricing\PriceQuote;

/**
 * Агрегат заказа на доставку: адреса, посылка, зафиксированная цена и статус.
 *
 * Цена, прогноз и расстояние берутся из результата расчёта (M2) и не
 * пересчитываются: заказ хранит их как есть (FR-ORD-103).
 *
 * Статус меняется только методами перехода: каждый возвращает StatusTransition,
 * из которого вызывающий слой строит запись истории. Домен не знает ни о роли
 * пользователя, ни об авторе перехода — право на действие проверяет политика,
 * автор попадает в историю на уровне приложения (FR-ORD-127, FR-ORD-128).
 */
final class DeliveryOrder
{
    private OrderStatus $status;

    /**
     * @param OrderStatus $status текущий статус при восстановлении из хранилища;
     *                            новый заказ всегда создаётся в статусе «создан»
     */
    public function __construct(
        private readonly OrderNumber $number,
        private readonly TrackingCode $trackingCode,
        private readonly Address $pickupAddress,
        private readonly Address $dropoffAddress,
        private readonly Parcel $parcel,
        private readonly PriceQuote $quote,
        OrderStatus $status = OrderStatus::Created,
    ) {
        $this->status = $status;
    }

    /**
     * Номер заказа.
     */
    public function number(): OrderNumber
    {
        return $this->number;
    }

    /**
     * Публичный код трекинга.
     */
    public function trackingCode(): TrackingCode
    {
        return $this->trackingCode;
    }

    /**
     * Текущий статус.
     */
    public function status(): OrderStatus
    {
        return $this->status;
    }

    /**
     * Адрес забора.
     */
    public function pickupAddress(): Address
    {
        return $this->pickupAddress;
    }

    /**
     * Адрес доставки.
     */
    public function dropoffAddress(): Address
    {
        return $this->dropoffAddress;
    }

    /**
     * Посылка.
     */
    public function parcel(): Parcel
    {
        return $this->parcel;
    }

    /**
     * Зафиксированное расстояние маршрута.
     */
    public function distance(): Distance
    {
        return $this->quote->distance();
    }

    /**
     * Зафиксированная цена заказа.
     */
    public function price(): Money
    {
        return $this->quote->total();
    }

    /**
     * Зафиксированная разбивка цены, включая нулевые строки.
     *
     * @return list<BreakdownLine>
     */
    public function breakdown(): array
    {
        return $this->quote->lines();
    }

    /**
     * Зафиксированный прогноз доставки в минутах.
     */
    public function etaMinutes(): int
    {
        return $this->quote->etaMinutes();
    }

    /**
     * Тип транспорта, зафиксированный в заказе.
     */
    public function transport(): TransportType
    {
        return $this->quote->transport();
    }

    /**
     * Вид лёгкого транспорта, если он применим.
     */
    public function lightVehicleKind(): ?LightVehicleKind
    {
        return $this->quote->lightVehicleKind();
    }

    /**
     * Назначение курьера: «создан» → «назначен».
     *
     * @throws OrderStatusTransitionNotAllowed переход запрещён
     */
    public function assign(?string $reason = null): StatusTransition
    {
        return $this->transitionTo(OrderStatus::Assigned, $reason);
    }

    /**
     * Принятие заказа курьером: «назначен» → «принят».
     *
     * @throws OrderStatusTransitionNotAllowed переход запрещён
     */
    public function accept(?string $reason = null): StatusTransition
    {
        return $this->transitionTo(OrderStatus::Accepted, $reason);
    }

    /**
     * Забор посылки: «принят» → «забран».
     *
     * @throws OrderStatusTransitionNotAllowed переход запрещён
     */
    public function pickup(?string $reason = null): StatusTransition
    {
        return $this->transitionTo(OrderStatus::PickedUp, $reason);
    }

    /**
     * Вручение посылки: «забран» → «вручён».
     *
     * @throws OrderStatusTransitionNotAllowed переход запрещён
     */
    public function deliver(?string $reason = null): StatusTransition
    {
        return $this->transitionTo(OrderStatus::Delivered, $reason);
    }

    /**
     * Закрытие заказа: «вручён» → «закрыт».
     *
     * @throws OrderStatusTransitionNotAllowed переход запрещён
     */
    public function close(?string $reason = null): StatusTransition
    {
        return $this->transitionTo(OrderStatus::Closed, $reason);
    }

    /**
     * Отмена заказа отправителем или диспетчером до забора посылки.
     *
     * После забора переход запрещён (FR-ORD-124): пара статусов отсутствует
     * в таблице переходов, поэтому отмена отсекается тем же исключением.
     *
     * @throws OrderStatusTransitionNotAllowed переход запрещён
     */
    public function cancel(?string $reason = null): StatusTransition
    {
        return $this->transitionTo(OrderStatus::Cancelled, $reason);
    }

    /**
     * Неудачная попытка доставки: «забран» → «не выполнен».
     *
     * @throws OrderStatusTransitionNotAllowed переход запрещён
     */
    public function fail(?string $reason = null): StatusTransition
    {
        return $this->transitionTo(OrderStatus::Failed, $reason);
    }

    /**
     * Возврат в работу после неудачной попытки: «не выполнен» → «создан».
     *
     * @throws OrderStatusTransitionNotAllowed переход запрещён
     */
    public function reassign(?string $reason = null): StatusTransition
    {
        return $this->transitionTo(OrderStatus::Created, $reason);
    }

    /**
     * @throws OrderStatusTransitionNotAllowed переход не предусмотрен правилами
     */
    private function transitionTo(OrderStatus $target, ?string $reason): StatusTransition
    {
        OrderTransitions::assertAllowed($this->status, $target);

        $transition = new StatusTransition($this->status, $target, $reason);
        $this->status = $target;

        return $transition;
    }
}
