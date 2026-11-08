<?php

declare(strict_types=1);

namespace App\Http\Resources\Dispatch;

use App\Http\Resources\ApiResource;
use App\Models\Dispatch\CourierShift;
use Illuminate\Http\Request;

/**
 * Смена курьера в ответе API (ЧТЗ 8.1).
 *
 * Статуса в базе нет: open/closed вычисляется по незаполненному окончанию,
 * поэтому состояние не может разойтись с данными.
 *
 * @mixin CourierShift
 */
final class ShiftResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CourierShift $shift */
        $shift = $this->resource;
        $isOpen = $shift->isOpen();

        return [
            'id' => $shift->id,
            'courier_id' => $shift->courier_id,
            'zone_id' => $shift->zone_id,
            'started_at' => $shift->started_at->toIso8601String(),
            'ended_at' => $shift->ended_at?->toIso8601String(),
            'status' => $isOpen ? 'open' : 'closed',
            'is_open' => $isOpen,
        ];
    }
}
