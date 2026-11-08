<?php

declare(strict_types=1);

namespace App\Livewire\Cabinet;

use App\Actions\Order\CancelOrder;
use App\Domain\Order\OrderStatus;
use App\Domain\Order\OrderTransitions;
use App\Domain\Pricing\Money;
use App\Http\Problem\ApiException;
use App\Http\Requests\Order\CancelOrderRequest;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OrderStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Карточка заказа отправителя и отмена до забора (FR-UI-126, 127).
 *
 * Данные читает сам компонент: заказ, история и авторы загружаются одним
 * `loadMissing(['statusHistory.changedBy'])`, поэтому карточка обходится без N+1.
 * Отмена идёт через CancelOrder после `authorize('cancel')`; допустимость
 * перехода в «отменён» решает доменное правило OrderTransitions, а не шаблон.
 * Все подписи и суммы форматируются здесь, Blade не обращается к базе.
 */
final class OrderDetails extends Component
{
    /**
     * Идентификатор заказа: связь с моделью не хранится, чтобы состояние
     * компонента оставалось переносимым между запросами.
     */
    #[Locked]
    public int $orderId = 0;

    /**
     * Необязательная причина отмены.
     */
    public ?string $cancelReason = null;

    /**
     * Отмена выполняется: кнопка подтверждения занята (FR-UI-186).
     */
    public bool $cancelling = false;

    /**
     * Конфликт или отказ операции: алерт карточки (ЧТЗ 8.5).
     */
    public ?string $error = null;

    /**
     * Сообщение об успешной отмене.
     */
    public ?string $status = null;

    /**
     * Заказ текущего запроса: между mount и render не читается повторно.
     *
     * Свойство приватное, поэтому Livewire его не сериализует и в следующем
     * запросе данные читаются заново — карточка всегда показывает свежий статус.
     */
    private ?DeliveryOrder $cachedOrder = null;

    public function mount(int $orderId): void
    {
        $this->orderId = $orderId;

        // Чужой и несуществующий заказ одинаковы: 404 без раскрытия (FR-UI-119).
        $this->currentOrder();
    }

    /**
     * Отмена заказа отправителем или диспетчером.
     *
     * Переход в «отменён» проверяет домен: после забора CancelOrder вернёт
     * ORDER_STATUS_TRANSITION_NOT_ALLOWED, и это станет сообщением карточки,
     * а не ошибкой страницы (FR-UI-127, ЧТЗ 8.5).
     */
    public function cancel(CancelOrder $cancelOrder): void
    {
        $this->error = null;
        $this->status = null;

        $order = $this->currentOrder();

        // Отмена без authorize() запрещена контрактом ЧТЗ 8.2.
        $this->authorize('cancel', $order);

        Validator::make(
            ['cancelReason' => $this->cancelReason],
            ['cancelReason' => CancelOrderRequest::rulesFor()['reason']],
        )->validate();

        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $reason = trim((string) $this->cancelReason);

        $this->cancelling = true;

        try {
            $cancelOrder->handle($order, $user, $reason === '' ? null : $reason);

            $this->cancelReason = null;
            $this->status = (string) __('orders.messages.cancelled');

            // Карточка обновляется без перезагрузки: render читает заказ заново.
            $this->dispatch('order-cancelled', orderId: $this->orderId);
            $this->dispatch('notify', type: 'success', message: $this->status);
        } catch (ApiException $exception) {
            // Заказ уже забран или отменён: показываем причину и текущий статус.
            $this->error = $this->domainMessage($exception);
            $this->dispatch('notify', type: 'error', message: $this->error);
        } finally {
            $this->cancelling = false;
            // render перечитывает заказ: после отмены и конфликта виден новый статус.
            $this->cachedOrder = null;
        }
    }

    public function render(): View
    {
        $order = $this->currentOrder();

        return view('livewire.cabinet.order-details', [
            'details' => $this->details($order),
            'canCancel' => $this->canCancel($order),
        ]);
    }

    /**
     * Заказ текущего пользователя с историей и авторами.
     *
     * @throws NotFoundHttpException
     */
    private function currentOrder(): DeliveryOrder
    {
        if ($this->cachedOrder instanceof DeliveryOrder) {
            return $this->cachedOrder;
        }

        $order = DeliveryOrder::query()->findOrFail($this->orderId);

        /** @var User|null $user */
        $user = Auth::user();

        abort_unless($user !== null && $user->can('view', $order), 404);

        // Один запрос за историей и один за авторами: N+1 в карточке нет.
        $order->loadMissing(['statusHistory.changedBy']);

        return $this->cachedOrder = $order;
    }

    /**
     * Кнопка отмены: только для статусов, из которых переход в «отменён» разрешён.
     */
    private function canCancel(DeliveryOrder $order): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        return $user !== null
            && $user->can('cancel', $order)
            && OrderTransitions::isAllowed($order->status, OrderStatus::Cancelled);
    }

    /**
     * Сообщение доменного отказа: подробный текст с текущим статусом, иначе текст кода.
     */
    private function domainMessage(ApiException $exception): string
    {
        $detail = $exception->details[0]['message'] ?? null;

        if (is_string($detail) && $detail !== '') {
            return $detail;
        }

        $key = 'errors.web.'.$exception->errorCode->value;

        return trans()->has($key) ? (string) trans($key) : (string) __('ui.create_order.failed');
    }

    /**
     * Значения карточки в скалярной форме: Blade не знает о домене и базе.
     *
     * @return array{
     *     number: string,
     *     status: OrderStatus,
     *     tracking_code: string,
     *     transport: string,
     *     eta: string,
     *     distance: string,
     *     price: string,
     *     breakdown: list<array{label: string, amount: string}>,
     *     pickup: string,
     *     dropoff: string,
     *     parcel: array{weight: string, length: string, width: string, height: string, declared: string|null},
     *     requested_at: string,
     *     created_at: string,
     *     cancelled_at: string|null,
     *     history: list<array{status: string, from: string|null, author: string|null, reason: string|null, at: string}>
     * }
     */
    private function details(DeliveryOrder $order): array
    {
        $format = (string) __('ui.formats.datetime');
        $currency = $order->price_currency;

        $breakdown = array_map(
            static fn (array $line): array => [
                'label' => (string) ($line['label'] ?? ''),
                'amount' => Money::fromMinor((int) ($line['amount'] ?? 0), $currency)->format(),
            ],
            array_values($order->price_breakdown),
        );

        $history = array_values($order->statusHistory
            ->map(static function (OrderStatusHistory $record) use ($format): array {
                $author = $record->changedBy;

                return [
                    'status' => $record->to_status->label(),
                    'from' => $record->from_status?->label(),
                    'author' => $author instanceof User ? $author->name : null,
                    'reason' => $record->reason,
                    'at' => $record->created_at?->format($format) ?? '',
                ];
            })
            ->all());

        /** @var array<string, mixed> $parcel */
        $parcel = $order->parcel;
        $declared = $parcel['declared_value'] ?? null;

        return [
            'number' => $order->number,
            'status' => $order->status,
            'tracking_code' => $order->tracking_code,
            'transport' => $order->transport_type->label()
                .($order->light_vehicle_kind !== null ? ', '.$order->light_vehicle_kind->label() : ''),
            'eta' => (string) __('ui.create_order.minutes', ['minutes' => $order->eta_minutes]),
            'distance' => (string) __('ui.create_order.kilometers', ['distance' => $order->distance_km]),
            'price' => Money::fromMinor($order->price_amount, $currency)->format(),
            'breakdown' => $breakdown,
            'pickup' => $this->addressString($order->pickup_address),
            'dropoff' => $this->addressString($order->dropoff_address),
            'parcel' => [
                'weight' => (string) (int) ($parcel['weight_grams'] ?? 0),
                'length' => (string) (int) ($parcel['length_cm'] ?? 0),
                'width' => (string) (int) ($parcel['width_cm'] ?? 0),
                'height' => (string) (int) ($parcel['height_cm'] ?? 0),
                'declared' => is_array($declared)
                    ? Money::fromMinor(
                        (int) ($declared['amount'] ?? 0),
                        (string) ($declared['currency'] ?? $currency),
                    )->format()
                    : null,
            ],
            'requested_at' => $order->requested_at->format($format),
            'created_at' => $order->created_at->format($format),
            'cancelled_at' => $order->cancelled_at?->format($format),
            'history' => $history,
        ];
    }

    /**
     * Адрес одной строкой: район, улица и дом без пустых частей.
     *
     * @param array<string, mixed> $address
     */
    private function addressString(array $address): string
    {
        $parts = [];

        foreach (['district', 'street', 'house'] as $key) {
            $value = $address[$key] ?? null;

            if (is_scalar($value) && trim((string) $value) !== '') {
                $parts[] = (string) $value;
            }
        }

        return implode(', ', $parts);
    }
}
