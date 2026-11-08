<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Auth;

use App\Actions\Identity\VerifyEmail;
use App\Http\Controllers\Controller;
use App\Http\Problem\ApiException;
use Illuminate\View\View;

/**
 * Подтверждение почты по подписанной ссылке из письма.
 *
 * Доступно и гостю: ссылку могут открыть в другом браузере. Повторное
 * открытие валидной ссылки не ошибка, подделанная даёт страницу 403.
 */
final class VerifyEmailController extends Controller
{
    public function __invoke(int $id, string $hash, VerifyEmail $verifyEmail): View
    {
        $alreadyVerified = false;

        try {
            $alreadyVerified = $verifyEmail->handle($id, $hash);
        } catch (ApiException) {
            // Подпись верна, но ссылка подделана: ни деталей, ни стека.
            abort(403);
        }

        return view('auth.verify-result', ['alreadyVerified' => $alreadyVerified]);
    }
}
