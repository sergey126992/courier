<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Geography\ServiceArea;
use App\Models\Geography\ServiceZone;
use Illuminate\Http\Request;

/**
 * Зона обслуживания в ответе API.
 *
 * Граница отдаётся в том же формате, который принимает ServiceArea::fromArray,
 * поэтому ответ можно отправить обратно в PATCH без преобразований.
 *
 * @mixin ServiceZone
 */
final class ZoneResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ServiceZone $zone */
        $zone = $this->resource;

        return [
            'id' => $zone->id,
            'code' => $zone->code,
            'name' => $zone->name,
            'boundary' => ServiceArea::fromArray((array) $zone->boundary)->toArray(),
            'base_fee_amount' => $zone->base_fee_amount,
            'currency' => $zone->currency,
            'is_active' => $zone->is_active,
        ];
    }
}
