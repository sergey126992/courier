<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Доступ к личным данным пользователя.
 *
 * Политика находится по стандартному имени модели (App\Models\User ->
 * App\Policies\UserPolicy), поэтому отдельная регистрация в провайдере не нужна.
 * Правило «кому можно менять свой профиль» берётся из домена (Role), поэтому
 * проверок роли по месту в контроллерах нет.
 */
final class UserPolicy
{
    /**
     * Просмотр профиля: любой пользователь видит только свои данные.
     */
    public function viewProfile(User $actor, User $owner): bool
    {
        return $this->isOwner($actor, $owner);
    }

    /**
     * Изменение профиля: только свой и только ролью, которой это разрешено доменом.
     */
    public function updateProfile(User $actor, User $owner): bool
    {
        return $this->isOwner($actor, $owner) && $actor->role->canEditOwnProfile();
    }

    /**
     * Владелец определяется по идентификатору, а не по совпадению объектов.
     */
    private function isOwner(User $actor, User $owner): bool
    {
        $actorId = $actor->getKey();

        return $actorId !== null && $actorId === $owner->getKey();
    }
}
