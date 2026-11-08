<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Rules\UniqueEmail;
use Illuminate\Validation\Rules\Password;

/**
 * Общие наборы правил входа в аккаунт.
 *
 * Один список используют и FormRequest API, и Livewire-формы веба:
 * копий правил рядом с формой не появляется (ЧТЗ 8.2).
 */
final class AuthRules
{
    /**
     * Регистрация отправителя.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function registration(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', new UniqueEmail],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }

    /**
     * Запрос ссылки сброса: существование адреса не проверяется.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function passwordResetRequest(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * Установка нового пароля по ссылке из письма.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function passwordReset(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }
}
