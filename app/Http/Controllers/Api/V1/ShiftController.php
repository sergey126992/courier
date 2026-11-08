<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Dispatch\CloseShift;
use App\Actions\Dispatch\OpenShift;
use App\Http\Controllers\Controller;
use App\Http\Problem\ApiException;
use App\Http\Requests\Dispatch\OpenShiftRequest;
use App\Http\Resources\Dispatch\ShiftResource;
use App\Models\Dispatch\CourierShift;
use App\Models\User;
use Dedoc\Scramble\Attributes\Response as ResponseAttribute;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Смена курьера: открытие, текущая смена и закрытие (ЧТЗ 8.1).
 *
 * Права проверяет политика CourierShiftPolicy, правила времени и занятости —
 * домен. Контроллер только передаёт данные операции и собирает ответ.
 */
final class ShiftController extends Controller
{
    /**
     * Открытие смены: курьер с профилем, лимит частоты — на маршруте.
     */
    #[ResponseAttribute(status: Response::HTTP_CREATED, description: 'Смена открыта.')]
    public function store(OpenShiftRequest $request, OpenShift $openShift): ShiftResource
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        /** @var int|null $zoneId */
        $zoneId = $data['zone_id'] ?? null;

        $shift = $openShift->handle($this->currentUser($request), $zoneId);

        return ShiftResource::make($shift)->withStatus(Response::HTTP_CREATED);
    }

    /**
     * Текущая открытая смена курьера: нет смены — 404 (ЧТЗ 8.7).
     */
    public function current(Request $request): ShiftResource
    {
        $user = $this->currentUser($request);

        // Смены ведёт только курьер: диспетчер и администратор получают 403.
        if (! $user->can('viewAny', CourierShift::class)) {
            throw ApiException::forbidden();
        }

        $shift = CourierShift::query()
            ->where('courier_id', $user->getKey())
            ->open()
            ->latest('started_at')
            ->first();

        if ($shift === null) {
            throw ApiException::notFound();
        }

        return ShiftResource::make($shift);
    }

    /**
     * Закрытие своей смены; чужая смена неотличима от несуществующей.
     */
    public function close(Request $request, CourierShift $shift, CloseShift $closeShift): ShiftResource
    {
        $user = $this->currentUser($request);

        if (! $user->can('viewAny', CourierShift::class)) {
            throw ApiException::forbidden();
        }

        if (! $user->can('close', $shift)) {
            throw ApiException::notFound();
        }

        return ShiftResource::make($closeShift->handle($shift));
    }

    /**
     * Текущий пользователь: маршруты закрыты auth:sanctum, поэтому он всегда есть.
     */
    private function currentUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
