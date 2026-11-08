<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Http\Problem\ApiException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Проверка email и пароля — единственное место сравнения учётных данных.
 *
 * Операцию используют оба входа: API (`LoginUser` далее выдаёт токен) и веб
 * (`LoginForm` далее открывает сессию). Ответ на неверный email и неверный
 * пароль одинаков и по тексту, и по времени: для несуществующего адреса
 * по-прежнему выполняется проверка хеша-заглушки. Лимит считает только неудачи.
 */
final class AuthenticateUser
{
    /**
     * Хеш-заглушка для выравнивания времени ответа на несуществующий email.
     *
     * Это bcrypt-хеш случайной строки, а не пароль какого-либо аккаунта.
     */
    private const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

    public function __construct(private readonly LoginAttemptLimiter $limiter) {}

    /**
     * @throws ApiException когда учётные данные неверны или предел попыток исчерпан
     */
    public function handle(string $email, string $password, string $ip): User
    {
        // Адреса хранятся в нижнем регистре: нормализация не зависит от способа входа.
        $email = mb_strtolower(trim($email));

        $limitKey = $this->limiter->key($email, $ip);

        $this->limiter->ensureNotLimited($limitKey);

        $user = User::query()->where('email', $email)->first();

        // Ответ на неверный email и неверный пароль одинаков и по тексту, и по времени.
        if ($user === null) {
            Hash::check($password, self::DUMMY_HASH);

            $this->limiter->recordFailure($limitKey);

            throw ApiException::invalidCredentials();
        }

        if (! Hash::check($password, (string) $user->password)) {
            $this->limiter->recordFailure($limitKey);

            throw ApiException::invalidCredentials();
        }

        // Успешный вход не расходует лимит и обнуляет прошлые неудачи.
        $this->limiter->clear($limitKey);

        return $user;
    }
}
