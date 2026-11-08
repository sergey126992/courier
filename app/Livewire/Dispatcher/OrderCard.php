<?php

declare(strict_types=1);

namespace App\Livewire\Dispatcher;

use App\Actions\Dispatch\AssignCourier;
use App\Actions\Dispatch\FindCouriers;
use App\Actions\Dispatch\ReassignOrder;
use App\Domain\Dispatch\AssignmentStrategy;
use App\Domain\Dispatch\CourierCandidate;
use App\Domain\Order\OrderStatus;
use App\Domain\Order\OrderTransitions;
use App\Domain\Pricing\Money;
use App\Http\Problem\ApiException;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OrderNotificationStatus;
use App\Models\Order\OrderStatusHistory;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Карточка заказа диспетчера: кандидаты, назначение и переназначение курьера (FR-UI-134…138).
 *
 * Данные карточки читает сам компонент: заказ с историей и авторами грузится
 * одним `loadMissing(['statusHistory.changedBy'])`, поэтому рендер не делает
 * запрос на каждую строку истории. Подбор и запись выполняют только действия
 * `FindCouriers`, `AssignCourier` и `ReassignOrder`: правила стратегии и
 * проверки доступности курьера в компоненте не дублируются.
 *
 * Доступное действие определяется доменным правилом OrderTransitions, а не
 * литеральным перечнем статусов: «Назначить» доступно там, где разрешён
 * переход в «назначен», «Переназначить» — там, где заказ возвращается из
 * «не выполнен» в «создан» (FR-UI-187).
 */
final class OrderCard extends Component
{
    /**
     * Идентификатор заказа: модель не хранится, чтобы состояние компонента
     * оставалось переносимым между запросами.
     */
    #[Locked]
    public int $orderId = 0;

    /**
     * Правило подбора из интерфейса; null — значение config('dispatch.strategy').
     */
    public ?string $strategy = null;

    /**
     * Выбранный курьер; null — автоматический подбор первым кандидатом.
     */
    public ?int $selectedCourierId = null;

    /**
     * Кандидаты в скалярной форме: шаблон не знает о домене и базе.
     *
     * @var list<array{id: int, name: string, transport: string, distance: string|null, assignments: int, approach: string|null, has_position: bool}>
     */
    public array $candidates = [];

    /**
     * Фактически применённое правило подбора (значение AssignmentStrategy).
     */
    public ?string $appliedStrategy = null;

    /**
     * Кандидаты запрошены хотя бы раз: отделяет исходное состояние от пустого.
     */
    public bool $candidatesLoaded = false;

    /**
     * Доменный отказ: сообщение карточки с кодом и понятным текстом (FR-UI-138).
     */
    public ?string $error = null;

    /**
     * Подтверждение успешного назначения или переназначения.
     */
    public ?string $status = null;

    /**
     * Назначение выполняется: кнопки заняты (FR-UI-186).
     */
    public bool $assigning = false;

    /**
     * Заказ текущего запроса: между mount и render повторно не читается.
     */
    private ?DeliveryOrder $cachedOrder = null;

    public function mount(int $orderId): void
    {
        $this->orderId = $orderId;

        // Чужой и несуществующий заказ неразличимы: 404 без раскрытия (FR-UI-119).
        $this->currentOrder();
    }

    /**
     * Подбор кандидатов выбранным правилом (FR-UI-135).
     *
     * Пустой список — штатное состояние, а не ошибка страницы (FR-UI-136).
     * Отказ домена (заказ уже не «создан») показывается сообщением карточки.
     */
    public function loadCandidates(FindCouriers $findCouriers): void
    {
        $this->resetMessages();

        $order = $this->currentOrder();
        $this->authorize('viewCandidates', $order);

        Validator::make(['strategy' => $this->strategy], $this->strategyRules())->validate();

        try {
            $result = $findCouriers->handle($order, $this->normalizedStrategy());
        } catch (ApiException $exception) {
            $this->candidates = [];
            $this->appliedStrategy = null;
            $this->candidatesLoaded = true;
            $this->fail($exception);

            return;
        }

        $this->appliedStrategy = $result->strategy()->value;
        $this->candidates = array_map(
            fn (CourierCandidate $candidate): array => $this->candidateRow($candidate),
            $result->candidates(),
        );
        $this->candidatesLoaded = true;

        // Выбор сохраняется, если он есть среди новых кандидатов; иначе — победитель подбора.
        $this->keepSelection();
    }

    /**
     * Назначение курьера: выбранный либо автоматический подбор (FR-UI-137).
     *
     * Операция вызывается только после `authorize('assign')`; отказ домена
     * переводится в сообщение, выбранный курьер остаётся в форме.
     */
    public function assign(AssignCourier $assignCourier): void
    {
        $this->resetMessages();

        $order = $this->currentOrder();
        $this->authorize('assign', Assignment::class);

        $this->validateAssignment();

        $dispatcher = $this->dispatcher();

        $this->assigning = true;

        try {
            $assignment = $assignCourier->handle(
                $order,
                $dispatcher,
                $this->selectedCourierId,
                $this->strategyEnum(),
            );

            $this->complete($assignment, (string) __('dispatch.messages.assigned', ['name' => $this->courierName($assignment)]));
        } catch (ApiException $exception) {
            $this->fail($exception);
        } finally {
            $this->assigning = false;
            // render перечитывает заказ: после назначения и конфликта виден новый статус.
            $this->cachedOrder = null;
        }
    }

    /**
     * Переназначение после неудачной попытки (FR-UI-137, ЧТЗ 5.5 п. 11).
     */
    public function reassign(ReassignOrder $reassignOrder): void
    {
        $this->resetMessages();

        $order = $this->currentOrder();
        $this->authorize('assign', Assignment::class);

        $this->validateAssignment();

        $dispatcher = $this->dispatcher();

        $this->assigning = true;

        try {
            $assignment = $reassignOrder->handle(
                $order,
                $dispatcher,
                $this->selectedCourierId,
                $this->strategyEnum(),
            );

            $this->complete($assignment, (string) __('dispatch.messages.reassigned', ['name' => $this->courierName($assignment)]));
        } catch (ApiException $exception) {
            $this->fail($exception);
        } finally {
            $this->assigning = false;
            $this->cachedOrder = null;
        }
    }

    public function render(): View
    {
        $order = $this->currentOrder();

        return view('livewire.dispatcher.order-card', [
            'details' => $this->details($order),
            'notifications' => $this->notificationStatuses($order),
            'strategyOptions' => $this->strategyOptions(),
            'defaultStrategyLabel' => (string) __('dispatch.labels.strategy_default', [
                'strategy' => $this->defaultStrategy()->label(),
            ]),
            'appliedStrategyLabel' => $this->appliedStrategyLabel(),
            'canAssign' => OrderTransitions::isAllowed($order->status, OrderStatus::Assigned),
            'canReassign' => OrderTransitions::isAllowed($order->status, OrderStatus::Created),
            'canShowCandidates' => OrderTransitions::isAllowed($order->status, OrderStatus::Assigned),
        ]);
    }

    /**
     * Заказ с историей и авторами: два запроса вместо запроса на строку.
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

        abort_unless($user !== null && $user->can('viewCandidates', $order), 404);

        $order->loadMissing(['statusHistory.changedBy']);

        return $this->cachedOrder = $order;
    }

    /**
     * Успешная попытка: подтверждение, событие очереди и очистка кандидатов.
     */
    private function complete(Assignment $assignment, string $message): void
    {
        $this->status = $message;
        $this->candidates = [];
        $this->appliedStrategy = null;
        $this->candidatesLoaded = false;
        $this->selectedCourierId = null;

        // Только скалярные данные: модели и DOM-узлы в события не передаются (ЧТЗ 8.2).
        $this->dispatch('assignment-created', orderId: $this->orderId);
        $this->dispatch('notify', type: 'success', message: $message);
    }

    /**
     * Доменный отказ: код и понятный текст в сообщении карточки (FR-UI-138).
     */
    private function fail(ApiException $exception): void
    {
        $this->error = $this->domainMessage($exception).' ('.$exception->errorCode->value.')';

        $this->dispatch('notify', type: 'error', message: $this->error);
    }

    private function resetMessages(): void
    {
        $this->error = null;
        $this->status = null;
    }

    /**
     * Действующий диспетчер: без него назначение невозможно.
     */
    private function dispatcher(): User
    {
        $user = Auth::user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function strategyRules(): array
    {
        return ['strategy' => ['nullable', Rule::enum(AssignmentStrategy::class)]];
    }

    /**
     * Правило и выбранный курьер проверяются до вызова операции.
     */
    private function validateAssignment(): void
    {
        Validator::make(
            [
                'strategy' => $this->strategy,
                'selectedCourierId' => $this->selectedCourierId,
            ],
            array_merge($this->strategyRules(), [
                'selectedCourierId' => ['nullable', 'integer', 'exists:users,id'],
            ]),
        )->validate();
    }

    /**
     * Имя курьера из строки назначения: подтверждение и история говорят одно и то же.
     */
    private function courierName(Assignment $assignment): string
    {
        $assignment->loadMissing('courier');

        return (string) ($assignment->courier->name ?? '');
    }

    /**
     * Правило из интерфейса в скалярной форме; пустая строка — значение конфигурации.
     */
    private function normalizedStrategy(): ?string
    {
        $value = $this->strategy !== null ? trim($this->strategy) : '';

        return $value === '' ? null : $value;
    }

    /**
     * Выбранное правило или null для значения конфигурации.
     *
     * Проверка `strategyRules()` уже гарантирует корректное значение перечисления.
     */
    private function strategyEnum(): ?AssignmentStrategy
    {
        $value = $this->normalizedStrategy();

        return $value === null ? null : AssignmentStrategy::from($value);
    }

    /**
     * Правило подбора по умолчанию из config('dispatch.strategy').
     */
    private function defaultStrategy(): AssignmentStrategy
    {
        $configured = (string) config('dispatch.strategy');

        return AssignmentStrategy::tryFrom($configured) ?? AssignmentStrategy::Nearest;
    }

    /**
     * Подпись фактически применённого правила (FR-UI-135).
     */
    private function appliedStrategyLabel(): ?string
    {
        if ($this->appliedStrategy === null) {
            return null;
        }

        return AssignmentStrategy::tryFrom($this->appliedStrategy)?->label();
    }

    /**
     * Варианты выбора правила подбора: значения домена и их подписи.
     *
     * @return array<string, string>
     */
    private function strategyOptions(): array
    {
        $options = [];

        foreach (AssignmentStrategy::cases() as $strategy) {
            $options[$strategy->value] = $strategy->label();
        }

        return $options;
    }

    /**
     * Выбор курьера: прежний, если он ещё в списке, иначе победитель подбора.
     */
    private function keepSelection(): void
    {
        $ids = array_map(
            static fn (array $candidate): int => $candidate['id'],
            $this->candidates,
        );

        if ($this->selectedCourierId !== null && in_array($this->selectedCourierId, $ids, true)) {
            return;
        }

        $this->selectedCourierId = $ids[0] ?? null;
    }

    /**
     * Строка кандидата: имя, транспорт, расстояние, назначения за смену и подход.
     *
     * @return array{id: int, name: string, transport: string, distance: string|null, assignments: int, approach: string|null, has_position: bool}
     */
    private function candidateRow(CourierCandidate $candidate): array
    {
        $transport = $candidate->transport()->label();

        if ($candidate->lightVehicleKind() !== null) {
            $transport .= ', '.$candidate->lightVehicleKind()->label();
        }

        $distance = $candidate->distanceToPickupKm();
        $approach = $candidate->pickupApproachMinutes();

        return [
            'id' => $candidate->courierId(),
            'name' => $candidate->name(),
            'transport' => $transport,
            // Отсутствие позиции помечается явно, а не пустой ячейкой (FR-UI-135).
            'distance' => $distance !== null
                ? (string) __('ui.create_order.kilometers', ['distance' => $distance])
                : null,
            'assignments' => $candidate->assignmentsInShift(),
            'approach' => $approach !== null
                ? (string) __('ui.create_order.minutes', ['minutes' => $approach])
                : null,
            'has_position' => $candidate->hasPosition(),
        ];
    }

    /**
     * Сообщение доменного отказа: подробный текст, иначе текст кода.
     */
    private function domainMessage(ApiException $exception): string
    {
        $detail = $exception->details[0]['message'] ?? null;

        if (is_string($detail) && $detail !== '') {
            return $detail;
        }

        $key = 'errors.web.'.$exception->errorCode->value;

        return trans()->has($key) ? (string) trans($key) : (string) __('ui.states.load_error');
    }

    /**
     * Значения карточки в скалярной форме: Blade не знает о домене и базе.
     *
     * @return array{
     *     number: string,
     *     status: OrderStatus,
     *     pickup: string,
     *     dropoff: string,
     *     transport: string,
     *     weight: string,
     *     price: string,
     *     eta: string,
     *     created_at: string,
     *     requested_at: string,
     *     history: list<array{status: string, author: string|null, reason: string|null, at: string}>
     * }
     */
    private function details(DeliveryOrder $order): array
    {
        $format = (string) __('ui.formats.datetime');

        /** @var array<string, mixed> $parcel */
        $parcel = $order->parcel;

        $history = array_values($order->statusHistory
            ->map(static function (OrderStatusHistory $record) use ($format): array {
                $author = $record->changedBy;

                return [
                    'status' => $record->to_status->label(),
                    'author' => $author instanceof User ? $author->name : null,
                    'reason' => $record->reason,
                    'at' => $record->created_at?->format($format) ?? '',
                ];
            })
            ->all());

        return [
            'number' => $order->number,
            'status' => $order->status,
            'pickup' => $this->addressString($order->pickup_address),
            'dropoff' => $this->addressString($order->dropoff_address),
            'transport' => $order->transport_type->label()
                .($order->light_vehicle_kind !== null ? ', '.$order->light_vehicle_kind->label() : ''),
            'weight' => (string) (int) ($parcel['weight_grams'] ?? 0),
            'price' => Money::fromMinor($order->price_amount, $order->price_currency)->format(),
            'eta' => (string) __('ui.create_order.minutes', ['minutes' => $order->eta_minutes]),
            'created_at' => $order->created_at->format($format),
            'requested_at' => $order->requested_at->format($format),
            'history' => $history,
        ];
    }

    /**
     * Статус доставки уведомлений по каналам (FR-NTF-284, FR-NTF-285).
     *
     * Единственный источник — проекция `order_notification_status`, которую
     * заполняет потребитель обратных событий сервиса оповещений: локального
     * журнала в монолите нет (FR-NTF-403, FR-NTF-406). Подписи канала и
     * состояния берутся из `lang`, неизвестный код показывается как есть.
     *
     * @return list<array{channel: string, status: string, state: string}>
     */
    private function notificationStatuses(DeliveryOrder $order): array
    {
        // По каналу показывается последняя строка проекции: у одного канала
        // бывает несколько уведомлений (создание и доставка заказа).
        return array_values(OrderNotificationStatus::query()
            ->where('order_id', $order->getKey())
            ->orderBy('id')
            ->get()
            ->keyBy('channel')
            ->sortKeys()
            ->map(fn (OrderNotificationStatus $row): array => [
                'channel' => $this->notificationLabel('notification_channel_'.$row->channel, (string) $row->channel),
                'status' => $this->notificationLabel('notification_status_'.$row->status, (string) $row->status),
                'state' => (string) $row->status,
            ])
            ->all());
    }

    /**
     * Подпись из lang по суффиксу ключа: нет перевода — исходный код.
     */
    private function notificationLabel(string $key, string $fallback): string
    {
        $full = 'orders.labels.'.$key;

        return trans()->has($full) ? (string) trans($full) : $fallback;
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
