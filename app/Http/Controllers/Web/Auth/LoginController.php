<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Actions\Identity\AuthenticateUser;
use App\Actions\Identity\RoleHomeUrl;
use App\Http\Controllers\Controller;
use App\Http\Problem\ApiException;
use App\Http\Problem\ErrorCode;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Вход по маршруту `login.store`, когда форма отправлена без Livewire.
 *
 * Проверка учётных данных живёт только в AuthenticateUser (FR-UI-102):
 * контроллер не сравнивает пароль, а открывает сессию и возвращает
 * на запомненный адрес или на домашнюю страницу роли (ЧТЗ 8.4).
 */
final class LoginController extends Controller
{
    public function __invoke(
        LoginRequest $request,
        AuthenticateUser $authenticate,
        RoleHomeUrl $homeUrl,
    ): RedirectResponse {
        try {
            $user = $authenticate->handle(
                $request->string('email')->toString(),
                $request->string('password')->toString(),
                (string) $request->ip(),
            );
        } catch (ApiException $exception) {
            return back()->withInput($request->only('email'))->withErrors([
                'email' => $this->failureMessage($exception),
            ]);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended($homeUrl->handle($user));
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
