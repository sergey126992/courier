<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Problem\ErrorCode;
use App\Services\Notifications\Service\NotificationServiceClient;
use App\Services\Notifications\Service\NotificationServiceUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Повтор записи DLQ из административного интерфейса (ЧТЗ 8.2, FR-NTF-511).
 *
 * Ответ повторяет API сервиса: `202` — повтор поставлен, `404` — записи нет,
 * `409` — запись уже в повторе или разобрана, `503` — сервис недоступен.
 * Ошибки отдаются как `application/problem+json` с кодами сервиса
 * `DLQ_ENTRY_NOT_FOUND` и `DLQ_ENTRY_ALREADY_RESOLVED`; внутренний текст
 * исключения и персональные данные наружу не уходят.
 */
final class NotificationServiceDlqReplayController extends Controller
{
    public function __invoke(Request $request, int $id, NotificationServiceClient $client): JsonResponse
    {
        try {
            $result = $client->replayDlq($id);
        } catch (NotificationServiceUnavailable) {
            $result = null;
        }

        if ($result !== null && $result->ok) {
            return new JsonResponse(['status' => 'accepted', 'id' => $id], $result->status, [], ApiResponse::JSON_FLAGS);
        }

        $code = $result === null
            ? ErrorCode::NotificationServiceUnavailable
            : self::code($result->status, $result->errorCode);

        return new JsonResponse(
            [
                'type' => $code->type(),
                'title' => $code->title(),
                'status' => $code->status(),
                'detail' => $code->detail(),
                'instance' => '/'.ltrim($request->getPathInfo(), '/'),
                'code' => $code->value,
                'details' => [],
            ],
            $code->status(),
            ['Content-Type' => 'application/problem+json'],
            ApiResponse::JSON_FLAGS,
        );
    }

    /**
     * Код проблемы по ответу сервиса: пробрасываются только коды DLQ.
     */
    private static function code(int $status, ?string $errorCode): ErrorCode
    {
        $code = $errorCode === null ? null : ErrorCode::tryFrom($errorCode);

        if ($code === ErrorCode::DlqEntryNotFound || $code === ErrorCode::DlqEntryAlreadyResolved) {
            return $code;
        }

        return match ($status) {
            404 => ErrorCode::DlqEntryNotFound,
            409 => ErrorCode::DlqEntryAlreadyResolved,
            default => ErrorCode::NotificationServiceUnavailable,
        };
    }
}
