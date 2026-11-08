<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Problem\ApiException;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Не пускает пользователя с неподтверждённой почтой в личные разделы.
 *
 * Подключается псевдонимом `verified.api` к маршрутам следующих срезов.
 */
final class EnsureEmailIsVerified
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException('Unauthenticated.', ['sanctum']);
        }

        if ($user instanceof MustVerifyEmail && ! $user->hasVerifiedEmail()) {
            throw ApiException::emailNotVerified();
        }

        return $next($request);
    }
}
