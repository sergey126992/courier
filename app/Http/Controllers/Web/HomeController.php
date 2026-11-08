<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Actions\Identity\RoleHomeUrl;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Главная страница: лендинг гостя и переход в раздел роли (FR-UI-117).
 *
 * Домашний адрес роли берётся из конфигурации через RoleHomeUrl, поэтому
 * списка ролей и адресов в контроллере нет (FR-UI-116).
 */
final class HomeController extends Controller
{
    public function __invoke(Request $request, RoleHomeUrl $roleHomeUrl): View|RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return view('home');
        }

        return redirect()->to($roleHomeUrl->handle($user));
    }
}
