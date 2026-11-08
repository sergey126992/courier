<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Mail\VerifyEmailMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Постановка письма подтверждения в очередь.
 */
final class SendEmailVerification
{
    /**
     * @param string|null $routeName имя маршрута ссылки; null — значение по умолчанию письма
     */
    public function handle(User $user, ?string $routeName = null): void
    {
        // Подтверждённой почте повторное письмо не нужно.
        if ($user->hasVerifiedEmail()) {
            return;
        }

        Mail::to($user)->queue(new VerifyEmailMail($user, $routeName));
    }
}
