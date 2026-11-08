<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DlqEntryResource;
use App\Services\Notifications\DlqService;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Повтор записи DLQ (ЧТЗ 8.1, 8.4, FR-NTF-506, FR-NTF-507).
 *
 * Ответ `202` возвращает состояние записи после повтора; `404` — записи
 * или строки журнала нет; `409` — запись уже в повторе или разобрана.
 * Ошибки отдаёт `ProblemRenderer`: тело `application/problem+json`
 * с кодом `DLQ_ENTRY_NOT_FOUND` или `DLQ_ENTRY_ALREADY_RESOLVED`.
 */
final class DlqReplayController extends Controller
{
    public function __invoke(int $id, DlqService $dlq): JsonResponse
    {
        return ApiResponse::json(['data' => DlqEntryResource::replay($dlq->replay($id))], 202);
    }
}
