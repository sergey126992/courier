<?php

declare(strict_types=1);

namespace App\Http\Resources\Dispatch;

use App\Domain\Dispatch\CourierCandidate;
use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Кандидат на назначение в ответе API (ЧТЗ 8.2, п. 6).
 *
 * Полей ровно столько, сколько нужно диспетчеру для решения: кто это, на чём
 * едет, как далеко до забора, сколько назначений за смену и сколько ехать
 * до точки забора. Расстояние и время подхода пусты, если позиция неизвестна.
 */
final class CourierCandidateResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CourierCandidate $candidate */
        $candidate = $this->resource;

        return [
            'id' => $candidate->courierId(),
            'name' => $candidate->name(),
            'transport' => $candidate->transport()->value,
            'light_vehicle_kind' => $candidate->lightVehicleKind()?->value,
            'distance_to_pickup_km' => $candidate->distanceToPickupKm(),
            'assignments_in_shift' => $candidate->assignmentsInShift(),
            'pickup_approach_minutes' => $candidate->pickupApproachMinutes(),
        ];
    }
}
