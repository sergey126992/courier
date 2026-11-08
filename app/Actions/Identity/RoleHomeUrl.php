<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Models\User;

/**
 * Домашняя страница роли — единственное место её вычисления.
 *
 * Маршрут задан конфигурацией интерфейса (FR-UI-116), поэтому ни веб-вход,
 * ни редирект гостя не содержат литерального списка ролей и их адресов.
 */
final class RoleHomeUrl
{
    public function handle(User $user): string
    {
        return route((string) config('ui.roles.'.$user->role->value.'.home'));
    }
}
