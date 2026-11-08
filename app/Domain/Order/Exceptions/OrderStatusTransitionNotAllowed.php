<?php

declare(strict_types=1);

namespace App\Domain\Order\Exceptions;

use App\Domain\Order\OrderStatus;
use DomainException;

/**
 * Переход статуса заказа не предусмотрен правилами (ЧТЗ 5.2).
 *
 * Исключение несёт текущий статус и попытку перехода: вызывающий слой
 * показывает их клиенту в деталях ошибки.
 */
final class OrderStatusTransitionNotAllowed extends DomainException
{
    private function __construct(
        private readonly OrderStatus $fromStatus,
        private readonly OrderStatus $toStatus,
    ) {
        parent::__construct(sprintf(
            'Переход заказа из статуса «%s» в «%s» запрещён.',
            $fromStatus->label(),
            $toStatus->label(),
        ));
    }

    public static function make(OrderStatus $from, OrderStatus $to): self
    {
        return new self($from, $to);
    }

    /**
     * Статус, в котором заказ находится сейчас.
     */
    public function fromStatus(): OrderStatus
    {
        return $this->fromStatus;
    }

    /**
     * Статус, в который заказ пытались перевести.
     */
    public function toStatus(): OrderStatus
    {
        return $this->toStatus;
    }
}
