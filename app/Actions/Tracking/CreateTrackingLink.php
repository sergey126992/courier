<?php

declare(strict_types=1);

namespace App\Actions\Tracking;

use App\Http\Problem\ApiException;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Создание публичной подписанной ссылки трекинга (FR-TRK-131…133).
 *
 * Ссылка в базе не хранится: подпись stateless — код заказа и срок действия
 * закодированы в самой ссылке и проверяются middleware signed. Поэтому
 * повторный запрос просто создаёт новую ссылку с новым сроком, а таблицы
 * ссылок, миграции и уборки просроченных строк не нужно.
 */
final readonly class CreateTrackingLink
{
    /**
     * @param string|null $routeName имя маршрута: null — прежняя API-ссылка;
     *                               кабинет передаёт web-маршрут из config('ui.tracking.public_route')
     *
     * @throws ApiException заказ чужой или недоступен роли
     * @throws RouteNotFoundException имя маршрута не объявлено
     */
    public function handle(User $user, DeliveryOrder $order, ?string $routeName = null): TrackingLinkSnapshot
    {
        // Права те же, что у чтения трекинга; чужой заказ и заказ для роли без
        // доступа неотличимы от несуществующего (FR-TRK-131, ЧТЗ 8.7).
        if (! $user->can('view', $order)) {
            throw ApiException::notFound();
        }

        $expiresAt = Carbon::now()->addHours((int) config('tracking.link_ttl_hours'));

        // Имя маршрута не меняет подпись и срок: без параметра остаётся API-ссылка
        // (FR-TRK-133, поведение M6), с параметром — HTML-страница кабинета (FR-UI-129).
        $url = URL::temporarySignedRoute($routeName ?? 'tracking.public', $expiresAt, [
            'code' => $order->tracking_code,
        ]);

        return new TrackingLinkSnapshot($url, $expiresAt->toIso8601String());
    }
}
