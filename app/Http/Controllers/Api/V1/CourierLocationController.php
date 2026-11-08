<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tracking\RecordCourierLocation;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Problem\ApiException;
use App\Http\Requests\Tracking\CourierLocationRequest;
use App\Models\Dispatch\Assignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Heartbeat позиции курьера (ЧТЗ 8.4, FR-TRK-112…114).
 *
 * Отдельный контроллер, а не метод смены: у heartbeat свой маршрут, свой
 * лимит частоты и своя операция, а ресурса назначения он не касается.
 * Ответ 202 сообщает, что позиция принята; история геоточек не пишется.
 */
final class CourierLocationController extends Controller
{
    /**
     * Приём позиции курьера: нужна открытая смена, иначе 409.
     */
    public function store(CourierLocationRequest $request, RecordCourierLocation $recordLocation): JsonResponse
    {
        $courier = $this->currentUser($request);

        // Heartbeat выполняет только курьер: прочие роли получают 403.
        if (! $courier->can('actAsCourier', Assignment::class)) {
            throw ApiException::forbidden();
        }

        $recordedAt = $request->recordedAt() ?? now()->toDateTimeImmutable();

        $recordLocation->handle($courier, $request->coordinates(), $recordedAt);

        return ApiResponse::json([
            'data' => [
                'accepted' => true,
                'recorded_at' => $recordedAt->format(DATE_ATOM),
            ],
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Текущий пользователь: маршруты закрыты auth:sanctum, поэтому он всегда есть.
     */
    private function currentUser(CourierLocationRequest $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
