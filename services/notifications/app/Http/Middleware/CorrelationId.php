<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Logging\TechnicalLogContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сквозной идентификатор HTTP-запроса сервиса (FR-OPS-201).
 *
 * Монолит передаёт `X-Request-Id` запроса пользователя при проксировании
 * служебного API, поэтому запись сервиса связывается с исходным действием
 * администратора. Внешний идентификатор принимается только по шаблону:
 * произвольная строка клиента в журнал не попадает. Заголовок возвращается
 * в ответе, значение становится `correlation_id` технического контекста
 * журнала на время запроса.
 */
final class CorrelationId
{
    /**
     * Заголовок запроса и ответа.
     */
    public const HEADER = 'X-Request-Id';

    /**
     * Шаблон допустимого внешнего идентификатора: 8–128 символов.
     */
    private const PATTERN = '/^[A-Za-z0-9._-]{8,128}$/';

    public function __construct(private readonly TechnicalLogContext $context) {}

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = $this->resolve($request);

        $this->context->replace(['correlation_id' => $correlationId]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $correlationId);

        return $response;
    }

    /**
     * Идентификатор запроса из заголовка либо новый UUID (FR-OPS-201).
     */
    private function resolve(Request $request): string
    {
        $value = $request->header(self::HEADER);

        if (is_string($value) && preg_match(self::PATTERN, $value) === 1) {
            return $value;
        }

        return (string) Str::uuid();
    }
}
