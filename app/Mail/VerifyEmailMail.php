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
 * Письмо подтверждения адреса.
 *
 * Уходит через очередь; текст берётся из lang/, пароля и служебных данных в письме нет.
 */
final class VerifyEmailMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /**
     * @param string|null $routeName имя маршрута ссылки; null — API-маршрут письма
     */
    public function __construct(public User $user, public ?string $routeName = null) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: (string) __('mail.verify.subject'));
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.verify-email',
            with: [
                'name' => $this->user->name,
                'url' => $this->verificationUrl(),
                'expireMinutes' => $this->expireMinutes(),
            ],
        );
    }

    /**
     * Подписанная ссылка ограниченного срока действия.
     *
     * Имя маршрута необязательно: без него остаётся прежний API-адрес,
     * а веб-сценарий передаёт web-маршрут из config('ui.links.*').
     */
    public function verificationUrl(?string $routeName = null): string
    {
        return URL::temporarySignedRoute(
            $routeName ?? $this->routeName ?? 'verification.verify',
            now()->addMinutes($this->expireMinutes()),
            [
                'id' => $this->user->getKey(),
                'hash' => sha1($this->user->getEmailForVerification()),
            ],
        );
    }

    private function expireMinutes(): int
    {
        return (int) config('auth.verification.expire', 60);
    }
}
