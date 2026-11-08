<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Входные данные установки нового пароля по ссылке из письма.
 *
 * Токен и адрес приходят в подписанной ссылке; Laravel читает параметры строки
 * запроса наравне с телом, поэтому дублировать их в теле не обязательно.
 */
final class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return AuthRules::passwordReset();
    }

    /**
     * Email приводится к нижнему регистру: адреса хранятся в нижнем регистре.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }
}
