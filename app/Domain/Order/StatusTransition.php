<?php

declare(strict_types=1);

namespace App\Domain\Order;

/**
 * Факт перехода статуса: откуда, куда и почему.
 *
 * Запись истории строится из этого объекта: автор перехода домену неизвестен,
 * его добавляет вызывающий слой (FR-ORD-125, FR-ORD-128).
 */
final readonly class StatusTransition
{
    public function __construct(
        private ?OrderStatus $from,
        private OrderStatus $to,
        private ?string $reason = null,
    ) {}

    /**
     * Прежний статус; null — первая запись истории при создании заказа.
     */
    public function from(): ?OrderStatus
    {
        return $this->from;
    }

    /**
     * Новый статус заказа.
     */
    public function to(): OrderStatus
    {
        return $this->to;
    }

    /**
     * Причина перехода, если она указана.
     */
    public function reason(): ?string
    {
        return $this->reason;
    }
}
