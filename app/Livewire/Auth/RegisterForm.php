<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Actions\Identity\RegisterSender;
use App\Http\Requests\Auth\AuthRules;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Форма регистрации отправителя.
 *
 * Правила валидации — тот же набор, что у API-запроса (RegisterRequest),
 * создание аккаунта и профиля — в RegisterSender. После успеха сессия
 * открыта, пользователь попадает на напоминание подтвердить почту (FR-UI-105).
 */
final class RegisterForm extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return AuthRules::registration();
    }

    /**
     * Создаёт аккаунт, открывает сессию и уводит на подтверждение почты.
     */
    public function register(RegisterSender $registerSender): void
    {
        $this->validate();

        $user = $registerSender->handle(
            trim($this->name),
            mb_strtolower(trim($this->email)),
            $this->password,
            (string) config('ui.links.email_verification_route'),
        );

        Auth::login($user);
        session()->regenerate();

        $this->redirectRoute('verification.notice');
    }

    public function render(): View
    {
        return view('livewire.auth.register-form');
    }
}
