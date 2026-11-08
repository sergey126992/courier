<?php

declare(strict_types=1);

namespace App\Http\Resources\Tracking;

use App\Actions\Tracking\OrderTrackingSnapshot;
use App\Http\Resources\ApiResource;
use App\Models\Order\DeliveryOrder;
use App\Models\Tracking\AssignmentLastPosition;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Ответ просмотра трекинга заказа (ЧТЗ 8.5).
 *
 * Состав: заказ, зафиксированный прогноз, пересчитанный остаток с источником,
 * последняя позиция, курьер и лента геоточек с блоком пагинации. Связи и
 * страница ленты уже загружены операцией: ресурс запросов не делает.
 */
final class OrderTrackingResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var OrderTrackingSnapshot $snapshot */
        $snapshot = $this->resource;

        /** @var DeliveryOrder $order */
        $order = $snapshot->order;

        $data = [
            'order' => [
                'number' => $order->number,
                'status' => $order->status->value,
                'status_label' => $order->status->label(),
            ],
            'eta_minutes' => $order->eta_minutes,
            // Зафиксированный прогноз: время заявки плюс прогноз на момент создания.
            // Не пересчитывается (FR-TRK-125).
            'eta_at' => $order->requested_at->copy()
                ->addMinutes($order->eta_minutes)
                ->toIso8601String(),
        ];

        // Остаток присутствует только у посчитанного прогноза (правило 2 ЧТЗ 8.5).
        if ($snapshot->remainingEta->isComputed()) {
            $data['remaining_eta_minutes'] = $snapshot->remainingEta->minutes();
        }

        $data['eta_source'] = $snapshot->remainingEta->source()->value;
        $data['last_position'] = $this->lastPosition($snapshot->lastPosition);
        $data['courier'] = $this->courier($snapshot->courier);
        $data['points'] = TrackingPointResource::collection($snapshot->points->getCollection())->resolve($request);
        $data['pagination'] = [
            'per_page' => $snapshot->points->perPage(),
            'has_more' => $snapshot->points->hasMorePages(),
            'next' => $snapshot->points->nextPageUrl(),
            'prev' => $snapshot->points->previousPageUrl(),
        ];

        return $data;
    }

    /**
     * Последняя известная позиция назначения.
     *
     * @return array{latitude: float, longitude: float, recorded_at: string}|null
     */
    private function lastPosition(?AssignmentLastPosition $position): ?array
    {
        if (! $position instanceof AssignmentLastPosition) {
            return null;
        }

        return [
            'latitude' => $position->latitude,
            'longitude' => $position->longitude,
            'recorded_at' => $position->recorded_at->toIso8601String(),
        ];
    }

    /**
     * Курьер назначения: имя и транспорт из его профиля.
     *
     * @return array{name: string, transport: string|null, light_vehicle_kind: string|null}|null
     */
    private function courier(?User $courier): ?array
    {
        if (! $courier instanceof User) {
            return null;
        }

        $profile = $courier->courierProfile;

        return [
            'name' => $courier->name,
            'transport' => $profile?->transport_type->value,
            'light_vehicle_kind' => $profile?->light_vehicle_kind?->value,
        ];
    }
}
