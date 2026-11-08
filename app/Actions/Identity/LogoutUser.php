<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Выход: отзывается только токен текущего запроса.
 *
 * Остальные токены пользователя (другие устройства) продолжают работать.
 */
final class LogoutUser
{
    public function handle(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }
}
