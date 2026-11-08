<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Http\Problem\ApiException;
use App\Models\User;

/**
 * Подтверждение email по подписи из письма.
 *
 * Повторное открытие валидной ссылки не является ошибкой.
 */
final class VerifyEmail
{
    /**
     * @return bool true, если адрес уже был подтверждён до этого вызова
     *
     * @throws ApiException когда ссылка подделана
     */
    public function handle(int $id, string $hash): bool
    {
        $user = User::query()->find($id);

        // Несуществующий пользователь неотличим от подделанной ссылки.
        if ($user === null || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw ApiException::invalidSignature();
        }

        // Повторное открытие валидной ссылки не ошибка: сообщаем об этом вызывающему.
        if ($user->hasVerifiedEmail()) {
            return true;
        }

        $user->markEmailAsVerified();

        return false;
    }
}
