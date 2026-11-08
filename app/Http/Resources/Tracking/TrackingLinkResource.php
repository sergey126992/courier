<?php

declare(strict_types=1);

namespace App\Http\Resources\Tracking;

use App\Actions\Tracking\TrackingLinkSnapshot;
use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Ответ создания публичной ссылки трекинга (ЧТЗ 8.6, FR-TRK-133).
 *
 * Только адрес ссылки и момент истечения: код заказа в ответ не дублируется.
 */
final class TrackingLinkResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var TrackingLinkSnapshot $snapshot */
        $snapshot = $this->resource;

        return [
            'url' => $snapshot->url,
            'expires_at' => $snapshot->expiresAt,
        ];
    }
}
