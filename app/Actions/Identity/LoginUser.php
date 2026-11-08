<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Http\Problem\ApiException;
use App\Models\User;

/**
 * Вход по email и паролю с выдачей токена доступа.
 *
 * Проверка учётных данных выполняется общей операцией AuthenticateUser:
 * сравнение пароля, выравнивание времени ответа и лимит попыток живут там,
 * а здесь остаётся только выдача токена с разрешениями роли.
 */
final class LoginUser
{
    /**
     * Название токена в хранилище: отделяет токены API от прочих.
     */
    private const TOKEN_NAME = 'api-v1';

    public function __construct(private readonly AuthenticateUser $authenticateUser) {}

    /**
     * @return array{user: User, token: string}
     *
     * @throws ApiException когда учётные данные неверны или предел попыток исчерпан
     */
    public function handle(string $email, string $password, string $ip): array
    {
        $user = $this->authenticateUser->handle($email, $password, $ip);

        $token = $user->createToken(self::TOKEN_NAME, $user->role->abilities())->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }
}
