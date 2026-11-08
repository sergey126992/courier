<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Actions\Identity\SendEmailVerification;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Повторная отправка письма подтверждения с экрана напоминания.
 */
final class ResendVerificationController extends Controller
{
    public function __invoke(Request $request, SendEmailVerification $sendEmailVerification): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $sendEmailVerification->handle($user, (string) config('ui.links.email_verification_route'));

        return back()->with('status', __('auth.verification_sent'));
    }
}
