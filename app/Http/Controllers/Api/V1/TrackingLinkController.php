<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tracking\CreateTrackingLink;
use App\Http\Controllers\Controller;
use App\Http\Resources\Tracking\TrackingLinkResource;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Создание публичной ссылки трекинга (ЧТЗ 8.6, FR-TRK-131…133).
 *
 * Права проверяет операция политикой заказа: чужой заказ и заказ для роли
 * без доступа дают 404, а не 403 (ЧТЗ 8.7). Лимит частоты — на маршруте.
 */
final class TrackingLinkController extends Controller
{
    public function __invoke(
        Request $request,
        DeliveryOrder $order,
        CreateTrackingLink $createTrackingLink,
    ): TrackingLinkResource {
        /** @var User $user */
        $user = $request->user();

        $link = $createTrackingLink->handle($user, $order);

        return TrackingLinkResource::make($link);
    }
}
