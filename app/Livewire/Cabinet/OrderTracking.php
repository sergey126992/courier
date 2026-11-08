<?php

declare(strict_types=1);

namespace App\Livewire\Cabinet;

use App\Actions\Tracking\OrderTrackingSnapshot;
use App\Actions\Tracking\ReadOrderTracking;
use App\Domain\Order\OrderStatus;
use App\Domain\Tracking\EtaSource;
use App\Models\Order\DeliveryOrder;
use App\Models\Tracking\AssignmentLastPosition;
use App\Models\Tracking\TrackingPoint;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Блок отслеживания заказа в кабинете (FR-UI-128).
 *
 * Данные читает только ReadOrderTracking: статус, последняя позиция,
 * зафиксированный прогноз, остаток пути с источником и последние события.
 * Остаток пути не считается при недоступном провайдере расстояний — операция
 * возвращает источник planned, и страница остаётся рабочей (FR-TRK-124).
 *
 * Автообновление — `wire:poll` по интервалу из конфигурации: в шаблоне нет
 * ни переходов, ни анимаций, поэтому при `prefers-reduced-motion: reduce`
 * обновление не сопровождается миганием (FR-UI-177).
 */
final class OrderTracking extends Component
{
    /**
     * Идентификатор заказа: модель между запросами не хранится.
     */
    #[Locked]
    public int $orderId = 0;

    /**
     * Интервал автообновления в секундах из config('ui.tracking.refresh_seconds').
     */
    public int $refreshSeconds = 0;

    /**
     * Готовые к показу данные текущего запроса: в состояние не сериализуются,
     * поэтому блок всегда показывает свежий статус заказа.
     *
     * @var array{
     *     status: OrderStatus,
     *     last_position: string|null,
     *     eta: string,
     *     remaining: string,
     *     source: string,
     *     events: list<array{at: string, position: string, source: string}>
     * }|null
     */
    private ?array $tracking = null;

    public function mount(int $orderId): void
    {
        $this->orderId = $orderId;
        $this->refreshSeconds = max(1, (int) config('ui.tracking.refresh_seconds'));

        // Чужой и несуществующий заказ одинаковы: 404 без раскрытия (FR-UI-119).
        $this->currentOrder();
    }

    /**
     * Периодическое обновление: данные перечитываются операцией, без перезагрузки.
     */
    public function refresh(): void
    {
        $this->tracking = null;
    }

    public function render(ReadOrderTracking $reader): View
    {
        return view('livewire.cabinet.order-tracking', [
            'tracking' => $this->tracking($reader),
        ]);
    }

    /**
     * Данные блока: операция чтения вызывается один раз на запрос.
     *
     * @return array{
     *     status: OrderStatus,
     *     last_position: string|null,
     *     eta: string,
     *     remaining: string,
     *     source: string,
     *     events: list<array{at: string, position: string, source: string}>
     * }
     */
    private function tracking(ReadOrderTracking $reader): array
    {
        if ($this->tracking !== null) {
            return $this->tracking;
        }

        $order = $this->currentOrder();

        /** @var User|null $user */
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        $snapshot = $reader->handle(
            $user,
            $order,
            null,
            (int) config('ui.pagination.per_page'),
        );

        $this->tracking = $this->present($snapshot);

        return $this->tracking;
    }

    /**
     * Заказ текущего пользователя: отказ политики — тот же 404, что и отсутствие.
     *
     * @throws NotFoundHttpException
     */
    private function currentOrder(): DeliveryOrder
    {
        $order = DeliveryOrder::query()->findOrFail($this->orderId);

        /** @var User|null $user */
        $user = Auth::user();

        abort_unless($user !== null && $user->can('view', $order), 404);

        return $order;
    }

    /**
     * Значения блока в скалярной форме: Blade не знает о домене и базе.
     *
     * @return array{
     *     status: OrderStatus,
     *     last_position: string|null,
     *     eta: string,
     *     remaining: string,
     *     source: string,
     *     events: list<array{at: string, position: string, source: string}>
     * }
     */
    private function present(OrderTrackingSnapshot $snapshot): array
    {
        $order = $snapshot->order;
        $format = (string) __('ui.formats.datetime');

        // Зафиксированный прогноз: время заявки плюс прогноз на момент создания
        // заказа. Остаток пути его не переписывает (FR-TRK-125).
        $etaAt = $order->requested_at->copy()->addMinutes($order->eta_minutes)->format($format);
        $remaining = $snapshot->remainingEta;
        $minutes = $remaining->minutes();

        $events = array_values($snapshot->points->getCollection()
            ->map(static fn (TrackingPoint $point): array => [
                'at' => $point->recorded_at->format((string) __('ui.formats.datetime')),
                'position' => self::coordinates($point->latitude, $point->longitude),
                'source' => $point->source->label(),
            ])
            ->all());

        return [
            'status' => $order->status,
            'last_position' => $snapshot->lastPosition instanceof AssignmentLastPosition
                ? self::coordinates($snapshot->lastPosition->latitude, $snapshot->lastPosition->longitude)
                    .', '.$snapshot->lastPosition->recorded_at->format($format)
                : null,
            'eta' => $etaAt,
            'remaining' => $minutes === null
                ? (string) __('tracking.labels.no_data')
                : (string) __('ui.create_order.minutes', ['minutes' => $minutes]),
            // Источник не угадывается: planned означает, что остаток не считался.
            'source' => $remaining->source() === EtaSource::Computed
                ? (string) __('tracking.labels.source_live')
                : (string) __('tracking.labels.source_planned'),
            'events' => $events,
        ];
    }

    /**
     * Координаты одной строкой: разделители — из правил формата интерфейса.
     */
    private static function coordinates(float $latitude, float $longitude): string
    {
        $decimal = (string) __('ui.formats.money_decimal_separator');

        return number_format($latitude, 5, $decimal, '').', '.number_format($longitude, 5, $decimal, '');
    }
}
