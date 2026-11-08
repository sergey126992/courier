<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Карточка заказа отправителя (FR-UI-126, ЧТЗ 8.1).
 *
 * Роль без доступа к заказам отсекается маршрутом (`can:viewAny`) и получает
 * 403. Отказ по конкретному заказу — 404: чужой заказ неотличим от
 * несуществующего, поэтому проверка `view` превращается в ту же страницу,
 * что и отсутствующий заказ (FR-UI-119).
 */
final class CabinetOrderController extends Controller
{
    public function show(Request $request, DeliveryOrder $order): View
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_unless($user !== null && $user->can('view', $order), 404);

        return view('cabinet.orders.show', ['order' => $order]);
    }

    /**
     * Трекинг заказа: те же права, что у карточки (FR-UI-128, ЧТЗ 8.1).
     *
     * Чужой заказ превращается в тот же 404, что и несуществующий: иначе
     * проверка `view` раскрывала бы существование чужого заказа (FR-UI-119).
     */
    public function tracking(Request $request, DeliveryOrder $order): View
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_unless($user !== null && $user->can('view', $order), 404);

        return view('cabinet.orders.tracking', ['order' => $order]);
    }
}
