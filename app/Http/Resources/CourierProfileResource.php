<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\CourierProfile;
use Illuminate\Http\Request;

/**
 * Профиль курьера: транспорт и признак активности (назначаются администратором).
 *
 * @mixin CourierProfile
 */
final class CourierProfileResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CourierProfile $profile */
        $profile = $this->resource;

        return [
            'transport_type' => $profile->transport_type->value,
            'light_vehicle_kind' => $profile->light_vehicle_kind?->value,
            'is_active' => $profile->is_active,
        ];
    }
}
