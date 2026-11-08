<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Admin;

use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Problem\ErrorCode;
use App\Services\Notifications\NotificationAdminJournal;
use App\Services\Notifications\RetryOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Повтор уведомления из административного интерфейса (ЧТЗ 8.2, FR-NTF-276).
 *
 * Ответ повторяет API активного отправителя: `202` — повтор поставлен,
 * `404` — уведомления нет, `409` — повтор невозможен, `503` — отправитель
 * недоступен. Ошибки отдаются как `application/problem+json` без внутреннего
 * текста сервиса и персональных данных.
 */
final class NotificationRetryController extends Controller
{
    public function __invoke(Request $request, int $id, NotificationAdminJournal $journal): JsonResponse
    {
        $outcome = $journal->retry($id);

        if ($outcome === RetryOutcome::Accepted) {
            return new JsonResponse(['status' => 'accepted', 'id' => $id], RetryOutcome::Accepted->status(), [], ApiResponse::JSON_FLAGS);
        }

        return self::problem($request, $outcome);
    }

    /**
     * Ответ problem+json по итогу повтора.
     */
    private static function problem(Request $request, RetryOutcome $outcome): JsonResponse
    {
        $code = match ($outcome) {
            RetryOutcome::NotFound => ErrorCode::NotFound,
            RetryOutcome::Conflict => ErrorCode::NotificationAlreadySent,
            default => ErrorCode::NotificationServiceUnavailable,
        };

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
}
