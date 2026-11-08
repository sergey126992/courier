<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Доступ к API сервиса по сервисному токену (FR-OPS-206, ЧТЗ 8.1).
 *
 * Токен приходит заголовком `Authorization: Bearer <token>` и сравнивается
 * с настроенным значением постоянным по времени способом. Без токена и
 * с неверным токеном ответ одинаковый — `401` без деталей; сам токен
 * не логируется и в ответ не попадает. `/health` остаётся без токена.
 */
final class AuthenticateServiceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('notifications.api.token');
        $provided = $request->bearerToken();

        if ($expected === '' || $provided === null || ! hash_equals($expected, $provided)) {
            return ProblemResponse::make(ErrorCode::InvalidServiceToken);
        }

        return $next($request);
    }
}
