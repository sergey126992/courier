<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Письмо со ссылкой сброса пароля.
 *
 * Уходит через очередь; ссылка подписана и ограничена сроком из config/auth.php.
 */
final class ResetPasswordMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param string|null $routeName имя маршрута ссылки; null — API-маршрут письма
     */
    public function __construct(public User $user, public string $token, public ?string $routeName = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('mail.reset.subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.reset-password',
            with: [
                'name' => $this->user->name,
                'url' => $this->resetUrl(),
                'expireMinutes' => $this->expireMinutes(),
            ],
        );
    }

    /**
     * Подписанная ссылка сброса: токен и адрес в строке запроса, подпись и срок — параметрами.
     *
     * Имя маршрута необязательно: без него остаётся прежний API-адрес,
     * а веб-сценарий передаёт web-маршрут из config('ui.links.*').
     */
    public function resetUrl(?string $routeName = null): string
    {
        return URL::temporarySignedRoute(
            $routeName ?? $this->routeName ?? 'auth.password.reset',
            now()->addMinutes($this->expireMinutes()),
            [
                'token' => $this->token,
                'email' => $this->user->email,
            ],
        );
    }

    private function expireMinutes(): int
    {
        return (int) config('auth.passwords.users.expire', 60);
    }
}
