<?php

declare(strict_types=1);

namespace App\Http\Problem;

use App\Http\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Единственное место, где исключения превращаются в ошибку API.
 *
 * Подключается в bootstrap/app.php. Формат ответа: RFC 7807 (application/problem+json)
 * с полями type, title, status, detail, instance, code и details.
 */
final class ProblemRenderer
{
    public function render(Throwable $exception, Request $request): ?Response
    {
        // Штатные страницы и служебные адреса не переводим в формат API.
        if (! $request->is('api/*')) {
            return null;
        }

        [$code, $details, $headers] = $this->map($exception);

        // Пояснение кода может быть уточнено данными запроса, например пределом периода.
        $detail = $exception instanceof ApiException && $exception->detail !== null
            ? $exception->detail
            : $code->detail();

        return response()->json(
            [
                'type' => $code->type(),
                'title' => $code->title(),
                'status' => $code->status(),
                'detail' => $detail,
                'instance' => '/'.ltrim($request->getPathInfo(), '/'),
                'code' => $code->value,
                'details' => $details,
            ],
            $code->status(),
            ['Content-Type' => 'application/problem+json'] + $headers,
            ApiResponse::JSON_FLAGS,
        );
    }

    /**
     * @return array{0: ErrorCode, 1: list<array{field: string, message: string}>, 2: array<string, string>}
     */
    private function map(Throwable $exception): array
    {
        if ($exception instanceof ApiException) {
            // Ответ о превышении лимита сообщает клиенту время повтора.
            $headers = $exception->retryAfter === null
                ? []
                : ['Retry-After' => (string) $exception->retryAfter];

            return [$exception->errorCode, $exception->details, $headers];
        }

        if ($exception instanceof ValidationException) {
            return [ErrorCode::ValidationFailed, $this->validationDetails($exception), []];
        }

        if ($exception instanceof AuthenticationException) {
            return [ErrorCode::InvalidCredentials, [], []];
        }

        if ($exception instanceof InvalidSignatureException) {
            return [ErrorCode::InvalidSignature, [], []];
        }

        if ($exception instanceof AuthorizationException || $exception instanceof AccessDeniedHttpException) {
            return [ErrorCode::Forbidden, [], []];
        }

        if ($exception instanceof ThrottleRequestsException) {
            /** @var array<string, string> $headers */
            $headers = $exception->getHeaders();

            return [ErrorCode::RateLimited, [], $headers];
        }

        if ($exception instanceof ModelNotFoundException || $exception instanceof NotFoundHttpException) {
            return [ErrorCode::NotFound, [], []];
        }

        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return [$this->mapStatus($exception->getStatusCode()), [], []];
        }

        // Всё остальное — 500 без трассировки и внутренних сообщений.
        return [ErrorCode::InternalError, [], []];
    }

    private function mapStatus(int $status): ErrorCode
    {
        return match ($status) {
            401 => ErrorCode::InvalidCredentials,
            403 => ErrorCode::Forbidden,
            404, 410 => ErrorCode::NotFound,
            422 => ErrorCode::ValidationFailed,
            429 => ErrorCode::RateLimited,
            default => ErrorCode::InternalError,
        };
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    private function validationDetails(ValidationException $exception): array
    {
        $details = [];

        foreach ($exception->errors() as $field => $messages) {
            foreach ($messages as $message) {
                $details[] = ['field' => (string) $field, 'message' => (string) $message];
            }
        }

        return $details;
    }
}
