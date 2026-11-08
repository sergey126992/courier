<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Dispatch\CourierShift;
use App\Models\User;

/**
 * Доступ к смене курьера (раздел 6 ЧТЗ).
 *
 * Смена — личный ресурс курьера: диспетчер и администратор операционных
 * действий этапа не выполняют и получают отказ. Чужой идентификатор смены
 * неотличим от несуществующего — это решает контроллер.
 */
final class CourierShiftPolicy
{
    /**
     * Маршруты смены доступны только роли курьера.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->role->canHandleAssignments();
    }

    /**
     * Открытие смены: курьер с профилем (FR-DSP-105).
     *
     * Курьер без профиля получает отказ в доступе, неактивный профиль
     * отсекает доменное правило кодом COURIER_INACTIVE.
     */
    public function create(User $actor): bool
    {
        return $actor->role->canHandleAssignments() && $actor->courierProfile()->exists();
    }

    /**
     * Просмотр смены: только её владелец.
     */
    public function view(User $actor, CourierShift $shift): bool
    {
        return $this->isOwner($actor, $shift);
    }

    /**
     * Закрытие смены: только её владелец.
     */
    public function close(User $actor, CourierShift $shift): bool
    {
        return $this->isOwner($actor, $shift);
    }

    /**
     * Владелец определяется по идентификатору, а не по совпадению объектов.
     */
    private function isOwner(User $actor, CourierShift $shift): bool
    {
        return $actor->getKey() !== null && $actor->getKey() === $shift->courier_id;
    }
}
