<?php

declare(strict_types=1);

namespace App\Http\Resources\Tracking;

use App\Http\Resources\ApiResource;
use App\Models\Tracking\TrackingPoint;
use Illuminate\Http\Request;

/**
 * Геоточка в ленте трекинга (ЧТЗ 8.5).
 *
 * Только координаты, время и источник: персональных данных в точке нет.
 *
 * @mixin TrackingPoint
 */
final class TrackingPointResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TrackingPoint $point */
        $point = $this->resource;

        return [
            'latitude' => $point->latitude,
            'longitude' => $point->longitude,
            'recorded_at' => $point->recorded_at->toIso8601String(),
            'source' => $point->source->value,
        ];
    }
}
