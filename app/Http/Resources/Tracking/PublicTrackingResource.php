<?php

declare(strict_types=1);

namespace App\Http\Resources\Tracking;

use App\Actions\Tracking\PublicTrackingSnapshot;
use App\Http\Resources\ApiResource;
use Illuminate\Http\Request;

/**
 * Публичный ответ трекинга (ЧТЗ 8.6, FR-TRK-134).
 *
 * Состав задаёт снимок: статус, подпись статуса, прогноз, район и время
 * обновления. Ресурс ничего не добавляет — персональных данных здесь нет.
 */
final class PublicTrackingResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PublicTrackingSnapshot $snapshot */
        $snapshot = $this->resource;

        return $snapshot->toArray();
    }
}
