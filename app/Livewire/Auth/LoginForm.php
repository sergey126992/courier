<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Actions\Identity\AuthenticateUser;
use App\Actions\Identity\RoleHomeUrl;
use App\Http\Problem\ApiException;
use App\Http\Problem\ErrorCode;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Форма входа: проверка учётных данных — в AuthenticateUser, дальше сессия.
 *
 * Веб-вход не создаёт токен Sanctum (FR-UI-103). Ошибки выводятся у полей,
 * а причина отказа не раскрывает, существует ли аккаунт (FR-UI-102, FR-UI-104).
 */
final class LoginForm extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    /**
     * Правила входа: причина отказа клиенту не раскрывается, пароль без деталей.
     *
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ];
    }

    /**
     * Открывает сессию после успешной проверки учётных данных.
     */
    public function login(AuthenticateUser $authenticate, RoleHomeUrl $homeUrl): void
    {
        $this->validate();

        try {
            $user = $authenticate->handle($this->email, $this->password, (string) request()->ip());
        } catch (ApiException $exception) {
            $this->addError('email', $this->failureMessage($exception));

            return;
        }

        Auth::login($user, $this->remember);
        session()->regenerate();

        // Запомненный адрес приоритетнее домашней страницы роли (ЧТЗ 8.4).
        $this->redirectIntended($homeUrl->handle($user));
    }

    public function render(): View
    {
        return view('livewire.auth.login-form');
    }

    /**
     * Текст отказа: для лимита — со временем повтора, иначе общий для входа.
     */
    private function failureMessage(ApiException $exception): string
    {
        if ($exception->errorCode !== ErrorCode::RateLimited) {
            return (string) __('auth.failed');
        }

        return (string) __('auth.throttle', ['seconds' => max(1, (int) $exception->retryAfter)]);
    }
}
