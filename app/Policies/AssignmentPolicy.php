<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Dispatch\Assignment;
use App\Models\User;

/**
 * Доступ к назначению курьера (раздел 6 ЧТЗ).
 *
 * Диспетчер видит все назначения и выполняет назначение и переназначение.
 * Курьер видит только свои попытки. Отправитель и администратор операционных
 * действий этапа не выполняют и получают отказ; чужое назначение контроллер
 * неотличимо от несуществующего.
 */
final class AssignmentPolicy
{
    /**
     * Список назначений доступен диспетчеру и курьеру.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->role->canDispatch() || $actor->role->canHandleAssignments();
    }

    /**
     * Карточка назначения: диспетчеру любая, курьеру — только своя.
     */
    public function view(User $actor, Assignment $assignment): bool
    {
        if ($actor->role->canDispatch()) {
            return true;
        }

        return $this->owns($actor, $assignment);
    }

    /**
     * Назначение и переназначение — только диспетчер (ЧТЗ 8.1).
     */
    public function assign(User $actor): bool
    {
        return $actor->role->canDispatch();
    }

    /**
     * Курьер ведёт свои назначения: список и действия по ним (ЧТЗ 8.1).
     *
     * Способ отделяет отказ по роли (403) от чужого назначения (404):
     * диспетчер, администратор и отправитель действий курьера не выполняют,
     * а чужое назначение курьеру неотличимо от несуществующего.
     */
    public function actAsCourier(User $actor): bool
    {
        return $actor->role->canHandleAssignments();
    }

    /**
     * Принятие назначения — только курьер-владелец (FR-DSP-141).
     */
    public function accept(User $actor, Assignment $assignment): bool
    {
        return $this->owns($actor, $assignment);
    }

    /**
     * Забор посылки — только курьер-владелец (FR-DSP-142).
     */
    public function pickup(User $actor, Assignment $assignment): bool
    {
        return $this->owns($actor, $assignment);
    }

    /**
     * Вручение посылки — только курьер-владелец (FR-DSP-143).
     */
    public function deliver(User $actor, Assignment $assignment): bool
    {
        return $this->owns($actor, $assignment);
    }

    /**
     * Неудачная попытка — только курьер-владелец (FR-DSP-144).
     */
    public function fail(User $actor, Assignment $assignment): bool
    {
        return $this->owns($actor, $assignment);
    }

    /**
     * Назначение принадлежит курьеру: диспетчер действия курьера не выполняет.
     */
    private function owns(User $actor, Assignment $assignment): bool
    {
        return $actor->role->canHandleAssignments()
            && $actor->getKey() !== null
            && $actor->getKey() === $assignment->courier_id;
    }
}
