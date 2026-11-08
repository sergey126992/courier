<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Identity\LogoutUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Выход: отзывается только текущий токен, тело ответа пустое.
 */
final class LogoutController extends Controller
{
    public function __invoke(Request $request, LogoutUser $logoutUser): Response
    {
        /** @var User $user */
        $user = $request->user();

        $logoutUser->handle($user);

        return response()->noContent();
    }
}
