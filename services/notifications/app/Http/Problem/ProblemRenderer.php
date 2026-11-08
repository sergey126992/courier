<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Domain\Notifications\Exceptions\NotificationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Единственное место, где исключения превращаются в ошибку API (ЧТЗ 8.5).
 *
 * Подключается в `bootstrap/app.php`. Для маршрутов `/api/*` ответ всегда
 * `application/problem+json` с кодом причины; текст исключения наружу
 * не передаётся (FR-OPS-207).
 */
final class ProblemRenderer
{
    public function render(Throwable $exception, Request $request): ?Response
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return ProblemResponse::make($this->map($exception));
    }

    private function map(Throwable $exception): ErrorCode
    {
        if ($exception instanceof ApiProblem) {
            return $exception->errorCode;
        }

        if ($exception instanceof ValidationException) {
            return ErrorCode::ValidationFailed;
        }

        if ($exception instanceof ModelNotFoundException || $exception instanceof NotFoundHttpException) {
            return ErrorCode::NotFound;
        }

        if ($exception instanceof HttpExceptionInterface) {
            return match ($exception->getStatusCode()) {
                401 => ErrorCode::InvalidServiceToken,
                404, 410 => ErrorCode::NotFound,
                409 => ErrorCode::NotificationAlreadySent,
                422 => ErrorCode::ValidationFailed,
                503 => ErrorCode::ServiceUnavailable,
                default => ErrorCode::InternalError,
            };
        }

        // Бизнес-ошибка с кодом, который входит в набор кодов API
        // (привязка Telegram), отдаётся своим статусом; остальные коды
        // причин наружу не выходят и остаются внутренней ошибкой.
        if ($exception instanceof NotificationException) {
            return ErrorCode::tryFrom($exception->errorCode()) ?? ErrorCode::InternalError;
        }

        return ErrorCode::InternalError;
    }
}
