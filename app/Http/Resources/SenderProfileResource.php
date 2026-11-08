<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SenderProfile;
use Illuminate\Http\Request;

/**
 * Профиль отправителя: телефон, компания и адрес по умолчанию.
 *
 * @mixin SenderProfile
 */
final class SenderProfileResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SenderProfile $profile */
        $profile = $this->resource;

        return [
            'phone' => $profile->phone,
            'company' => $profile->company,
            'default_address' => $profile->default_address,
        ];
    }
}
