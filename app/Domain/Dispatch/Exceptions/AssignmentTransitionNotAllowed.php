<?php

declare(strict_types=1);

namespace App\Domain\Dispatch\Exceptions;

use App\Domain\Dispatch\AssignmentStatus;
use DomainException;

/**
 * Переход статуса назначения не предусмотрен правилами (ЧТЗ 5.2).
 *
 * Исключение несёт текущий статус и попытку перехода: вызывающий слой
 * показывает их клиенту в деталях ошибки, как и для статусов заказа.
 */
final class AssignmentTransitionNotAllowed extends DomainException
{
    private function __construct(
        private readonly AssignmentStatus $fromStatus,
        private readonly AssignmentStatus $toStatus,
    ) {
        parent::__construct(sprintf(
            'Переход назначения из статуса «%s» в «%s» запрещён.',
            $fromStatus->label(),
            $toStatus->label(),
        ));
    }

    public static function make(AssignmentStatus $from, AssignmentStatus $to): self
    {
        return new self($from, $to);
    }

    /**
     * Статус, в котором назначение находится сейчас.
     */
    public function fromStatus(): AssignmentStatus
    {
        return $this->fromStatus;
    }

    /**
     * Статус, в который назначение пытались перевести.
     */
    public function toStatus(): AssignmentStatus
    {
        return $this->toStatus;
    }
}
