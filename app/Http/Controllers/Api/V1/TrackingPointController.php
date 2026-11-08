<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tracking\RecordTrackingPoint;
use App\Http\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Problem\ApiException;
use App\Http\Requests\Tracking\TrackingPointRequest;
use App\Models\Dispatch\Assignment;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Приём геоточки по назначению курьера (ЧТЗ 8.4, FR-TRK-101…108).
 *
 * Права: действие доступно только курьеру, а чужое назначение неотличимо от
 * несуществующего. Контроллер только передаёт данные операции и отвечает 202:
 * ответ означает «принято», пересчёт трекинга выполняется при чтении.
 */
final class TrackingPointController extends Controller
{
    /**
     * Приём точки: 202 без ожидания пересчёта трекинга (FR-TRK-107).
     */
    public function store(
        TrackingPointRequest $request,
        Assignment $assignment,
        RecordTrackingPoint $recordPoint,
    ): JsonResponse {
        $courier = $this->courierFor($request, $assignment);

        $point = $recordPoint->handle(
            $assignment,
            $courier,
            $request->coordinates(),
            $request->recordedAt(),
        );

        return ApiResponse::json([
            'data' => [
                'accepted' => true,
                'recorded_at' => $point->recorded_at->toIso8601String(),
            ],
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Курьер-владелец назначения: прочие роли — 403, чужое назначение — 404.
     */
    private function courierFor(TrackingPointRequest $request, Assignment $assignment): User
    {
        $user = $this->currentUser($request);

        if (! $user->can('actAsCourier', Assignment::class)) {
            throw ApiException::forbidden();
        }

        if ($user->getKey() !== $assignment->courier_id) {
            throw ApiException::notFound();
        }

        return $user;
    }

    /**
     * Текущий пользователь: маршруты закрыты auth:sanctum, поэтому он всегда есть.
     */
    private function currentUser(TrackingPointRequest $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
