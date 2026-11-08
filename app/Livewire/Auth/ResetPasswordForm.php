<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Actions\Identity\ResetPassword;
use App\Http\Problem\ApiException;
use App\Http\Requests\Auth\AuthRules;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Форма установки нового пароля по ссылке из письма.
 *
 * Правила — из ResetPasswordRequest; смена пароля — в ResetPassword,
 * которая отзывает и прежние токены API (ЧТЗ 5.2, пункт 12).
 */
final class ResetPasswordForm extends Component
{
    public string $email = '';

    public string $token = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * Ссылка из письма передаёт токен и адрес в адресе страницы.
     */
    public function mount(string $token = '', string $email = ''): void
    {
        $this->token = $token;
        $this->email = $email;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return AuthRules::passwordReset();
    }

    /**
     * Меняет пароль и возвращает на страницу входа с сообщением.
     *
     * Имя действия задано контрактом ЧТЗ 8.2. Livewire объявляет собственный
     * `reset(...$properties)` для сброса свойств, поэтому вызов без аргументов
     * устанавливает пароль, а вызов с именами свойств уходит в базовый метод.
     *
     * @param string ...$properties
     */
    public function reset(...$properties): void
    {
        if ($properties !== []) {
            parent::reset(...$properties);

            return;
        }

        $this->validate();

        try {
            app(ResetPassword::class)->handle(
                mb_strtolower(trim($this->email)),
                $this->token,
                $this->password,
            );
        } catch (ApiException) {
            // Ссылка использована, просрочена или подделана: текст без деталей.
            $this->addError('email', (string) __('errors.codes.INVALID_SIGNATURE.detail'));

            return;
        }

        session()->flash('status', __('auth.password_reset'));

        $this->redirectRoute('login');
    }

    public function render(): View
    {
        return view('livewire.auth.reset-password-form');
    }
}
