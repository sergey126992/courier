<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/**
 * Запрос сброса пароля.
 *
 * Ответ не зависит от существования аккаунта: письмо уходит только владельцу
 * адреса, а вызывающий код всегда отвечает одинаково.
 */
final class SendPasswordResetLink
{
    /**
     * @param string|null $routeName имя маршрута ссылки; null — значение по умолчанию письма
     */
    public function handle(string $email, ?string $routeName = null): void
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return;
        }

        // Токен хранится в базе в виде хеша; срок жизни задаёт config/auth.php.
        $token = Password::broker()->createToken($user);

        Mail::to($user)->queue(new ResetPasswordMail($user, $token, $routeName));
    }
}
