<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Actions\Identity\ResetPassword;
use App\Http\Controllers\Controller;
use App\Http\Problem\ApiException;
use App\Http\Requests\Auth\AuthRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Страница и установка нового пароля по подписанной ссылке из письма.
 *
 * GET открывает форму, POST (без Livewire) меняет пароль и возвращает
 * на страницу входа с сообщением (FR-UI-107).
 */
final class ResetPasswordController extends Controller
{
    public function __invoke(Request $request, string $token, ResetPassword $resetPassword): View|RedirectResponse
    {
        if (! $request->isMethod('post')) {
            return view('auth.reset-password', [
                'token' => $token,
                'email' => (string) $request->query('email', ''),
            ]);
        }

        // Токен приходит параметром маршрута: добавляем его к данным проверки.
        $request->merge(['token' => $token]);
        $validated = $request->validate(AuthRules::passwordReset());

        try {
            $resetPassword->handle(
                (string) $validated['email'],
                (string) $validated['token'],
                (string) $validated['password'],
            );
        } catch (ApiException) {
            // Использованная или подделанная ссылка: HTML-страница без деталей.
            abort(403);
        }

        return redirect()->route('login')->with('status', __('auth.password_reset'));
    }
}
