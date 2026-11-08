<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Actions\Identity\SendPasswordResetLink;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Запрос ссылки сброса по маршруту `password.email`.
 *
 * Ответ одинаков для существующего и несуществующего адреса (FR-UI-107).
 */
final class ForgotPasswordController extends Controller
{
    public function __invoke(
        ForgotPasswordRequest $request,
        SendPasswordResetLink $sendPasswordResetLink,
    ): RedirectResponse {
        $sendPasswordResetLink->handle(
            $request->string('email')->toString(),
            (string) config('ui.links.password_reset_route'),
        );

        return redirect()
            ->route('password.request')
            ->with('status', __('auth.password_reset_sent'));
    }
}
