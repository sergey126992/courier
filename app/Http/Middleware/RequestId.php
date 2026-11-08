<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сквозной идентификатор запроса (FR-OPS-101, FR-OPS-102).
 *
 * Внешний идентификатор принимается только по шаблону: иначе в заголовки и
 * журнал попала бы произвольная строка клиента. Значение возвращается в
 * ответе и добавляется в лог-контекст, поэтому записи одного запроса можно
 * связать между собой; уже добавленный контекст не затирается.
 *
 * Контракты ответов не меняются: добавляется только заголовок.
 *
 * Middleware зарегистрирован глобально (bootstrap/app.php): заголовок есть на
 * любом ответе приложения, в том числе на 404 до маршрутизации и на 500.
 */
final class RequestId
{
    /**
     * Заголовок запроса и ответа.
     */
    public const HEADER = 'X-Request-Id';

    /**
     * Ключ лог-контекста и атрибута запроса.
     */
    public const CONTEXT_KEY = 'request_id';

    /**
     * Шаблон допустимого внешнего идентификатора: 8–128 символов.
     */
    private const PATTERN = '/^[A-Za-z0-9._-]{8,128}$/';

    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolve($request);

        // Значение доступно обработчикам запроса и попадает во все записи
        // журнала, сделанные во время этого запроса (FR-OPS-102).
        $request->attributes->set(self::CONTEXT_KEY, $requestId);
        Log::withContext([self::CONTEXT_KEY => $requestId]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $requestId);

        return $response;
    }

    /**
     * Идентификатор из заголовка запроса либо новый UUID (FR-OPS-101).
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
