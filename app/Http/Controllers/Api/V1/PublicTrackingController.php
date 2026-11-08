<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tracking\ReadPublicTracking;
use App\Http\Controllers\Controller;
use App\Http\Resources\Tracking\PublicTrackingResource;

/**
 * Публичный трекинг по подписанной ссылке (ЧТЗ 8.6, FR-TRK-134…138).
 *
 * Маршрут единственный без токена: подпись проверяет middleware signed,
 * частоту обращений — лимит по IP. Контроллер только передаёт код операции.
 */
final class PublicTrackingController extends Controller
{
    public function __invoke(string $code, ReadPublicTracking $readPublicTracking): PublicTrackingResource
    {
        $snapshot = $readPublicTracking->handle($code);

        return PublicTrackingResource::make($snapshot);
    }
}
