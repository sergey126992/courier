<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Http\Problem\ApiException;
use App\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Password;

/**
 * Установка нового пароля по ссылке из письма.
 *
 * Токен одноразовый: брокер паролей проверяет его и удаляет после успешной смены.
 * Вместе с паролем отзываются все ранее выданные токены доступа.
 */
final class ResetPassword
{
    /**
     * @throws ApiException когда ссылка уже использована, просрочена или неверна
     */
    public function handle(string $email, string $token, string $password): void
    {
        $status = Password::broker()->reset(
            [
                'email' => $email,
                'token' => $token,
                'password' => $password,
            ],
            static function (User $user, string $password): void {
                $user->forceFill(['password' => $password])->save();

                // Сброс пароля закрывает доступ всех ранее выданных токенов.
                $user->tokens()->delete();
            },
        );

        if ($status !== PasswordBroker::PASSWORD_RESET) {
            throw ApiException::invalidSignature();
        }
    }
}
