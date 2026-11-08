<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Actions\Identity\RegisterSender;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Регистрация по маршруту `register.store`, когда форма отправлена без Livewire.
 *
 * Валидация — в RegisterRequest, создание аккаунта и профиля — в RegisterSender.
 * После успеха сессия открыта, пользователь уходит на `verification.notice`.
 */
final class RegisterController extends Controller
{
    public function __invoke(RegisterRequest $request, RegisterSender $registerSender): RedirectResponse
    {
        $user = $registerSender->handle(
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            $request->string('password')->toString(),
            (string) config('ui.links.email_verification_route'),
        );

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('verification.notice');
    }
}
