<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Actions\Identity\SendPasswordResetLink;
use App\Http\Requests\Auth\AuthRules;
use Illuminate\View\View;
use Livewire\Component;

/**
 * Форма запроса ссылки сброса пароля.
 *
 * Правила — из ForgotPasswordRequest; сам ответ не раскрывает, существует
 * ли аккаунт: письмо уходит только владельцу адреса (FR-UI-107).
 */
final class ForgotPasswordForm extends Component
{
    public string $email = '';

    /**
     * Показывает нейтральное сообщение об отправке вместо формы.
     */
    public bool $sent = false;

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return AuthRules::passwordResetRequest();
    }

    /**
     * Ставит письмо со ссылкой сброса в очередь.
     */
    public function send(SendPasswordResetLink $sendPasswordResetLink): void
    {
        $this->validate();

        $sendPasswordResetLink->handle(
            mb_strtolower(trim($this->email)),
            (string) config('ui.links.password_reset_route'),
        );

        $this->sent = true;
    }

    public function render(): View
    {
        return view('livewire.auth.forgot-password-form');
    }
}
