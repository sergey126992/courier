<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Geography\ServiceZone;
use App\Models\User;

/**
 * Доступ к справочнику зон обслуживания.
 *
 * Правило роли живёт в домене (Role::canManageReferences), поэтому здесь только
 * делегирование: проверок роли по месту в контроллерах нет. Политика
 * регистрируется явно в AppServiceProvider: модель лежит в подпапке Geography.
 */
final class ServiceZonePolicy
{
    /**
     * Список зон видит только администратор.
     */
    public function viewAny(User $actor): bool
    {
        return $actor->role->canManageReferences();
    }

    /**
     * Создание зоны: только администратор.
     */
    public function create(User $actor): bool
    {
        return $actor->role->canManageReferences();
    }

    /**
     * Изменение и деактивация зоны: только администратор.
     */
    public function update(User $actor, ServiceZone $zone): bool
    {
        return $actor->role->canManageReferences();
    }
}
