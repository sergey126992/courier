<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Tracking\ReadOrderTracking;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tracking\OrderTrackingRequest;
use App\Http\Resources\Tracking\OrderTrackingResource;
use App\Models\Order\DeliveryOrder;
use App\Models\User;

/**
 * Просмотр трекинга заказа (ЧТЗ 8.5, FR-TRK-121…128).
 *
 * Отдельный контроллер, а не метод OrderController: чтение трекинга опирается
 * на собственное действие и ресурсы модуля трекинга, а OrderController уже
 * отвечает за жизненный цикл заказа. Контроллер только передаёт проверенные
 * данные операции и собирает ответ.
 */
final class OrderTrackingController extends Controller
{
    public function __invoke(
        OrderTrackingRequest $request,
        DeliveryOrder $order,
        ReadOrderTracking $readOrderTracking,
    ): OrderTrackingResource {
        /** @var User $user */
        $user = $request->user();

        $snapshot = $readOrderTracking->handle(
            $user,
            $order,
            $request->cursor(),
            $request->limit(),
        );

        return OrderTrackingResource::make($snapshot);
    }
}
