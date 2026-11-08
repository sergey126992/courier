<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Выход из веб-сессии (FR-UI-108).
 *
 * Сессия уничтожается, CSRF-токен обновляется; токены API не затрагиваются.
 * Повторный POST без сессии уходит на страницу входа через middleware `auth`.
 */
final class LogoutController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
