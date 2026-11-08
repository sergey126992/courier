<?php

declare(strict_types=1);

namespace App\Livewire\Cabinet;

use App\Actions\Tracking\CreateTrackingLink;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Публичная ссылка на отслеживание заказа (FR-UI-129).
 *
 * Ссылку создаёт CreateTrackingLink с именем web-маршрута из
 * config('ui.tracking.public_route'): скопированный адрес открывает HTML-страницу.
 * Вызов операции без параметра маршрута по-прежнему даёт API-ссылку, поэтому
 * контракт M6 не меняется.
 *
 * Копирование выполняет Alpine по нажатию кнопки, результат объявляется через
 * `aria-live`; анимаций нет, поэтому `prefers-reduced-motion: reduce` соблюдается.
 */
final class TrackingLinkButton extends Component
{
    /**
     * Идентификатор заказа: модель между запросами не хранится.
     */
    #[Locked]
    public int $orderId = 0;

    /**
     * Готовая публичная ссылка; null — ссылка ещё не создана.
     */
    public ?string $url = null;

    /**
     * Срок действия ссылки в формате интерфейса.
     */
    public ?string $expiresAt = null;

    /**
     * Причина отказа: сообщение блока вместо падения страницы.
     */
    public ?string $error = null;

    public function mount(int $orderId): void
    {
        $this->orderId = $orderId;
    }

    /**
     * Создание публичной ссылки: права — политика `view`, как у карточки.
     */
    public function create(CreateTrackingLink $links): void
    {
        $this->url = null;
        $this->expiresAt = null;
        $this->error = null;

        $order = DeliveryOrder::query()->findOrFail($this->orderId);

        // Действие без authorize() запрещено контрактом ЧТЗ 8.2.
        $this->authorize('view', $order);

        /** @var User|null $user */
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        $routeName = (string) config('ui.tracking.public_route');

        try {
            $snapshot = $links->handle($user, $order, $routeName);
        } catch (RouteNotFoundException) {
            // Имя маршрута пришло из конфигурации и может указывать на страницу,
            // которой нет: это реальная ошибка настройки, а не штатное состояние.
            $this->error = (string) __('tracking.messages.link_unavailable');

            return;
        }

        $this->url = $snapshot->url;
        $this->expiresAt = Carbon::parse($snapshot->expiresAt)
            ->format((string) __('ui.formats.datetime'));

        $this->dispatch('notify', type: 'success', message: (string) __('orders.messages.tracking_link_created'));
    }

    public function render(): View
    {
        return view('livewire.cabinet.tracking-link-button');
    }
}
