<?php

declare(strict_types=1);

namespace App\Actions\Dispatch;

use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Dispatch\AssignmentStrategy;
use App\Domain\Dispatch\Contracts\CourierAssignmentStrategy;
use App\Domain\Dispatch\CourierCandidate;
use App\Domain\Dispatch\CourierSelection;
use App\Domain\Order\Exceptions\OrderStatusTransitionNotAllowed;
use App\Domain\Order\OrderStatus;
use App\Http\Problem\ApiException;
use App\Models\Dispatch\Assignment;
use App\Models\Dispatch\CourierShift;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OrderStatusHistory;
use App\Models\Order\OutboxMessage;
use App\Models\User;
use App\Services\Dispatch\CandidateFinder;
use App\Services\Events\OrderEventPayload;
use App\Services\Order\OrderAggregateFactory;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Назначение курьера на заказ: автоматический подбор или конкретный курьер (FR-DSP-131…137).
 *
 * Порядок: блокировка заказа → проверка статуса → выбор кандидата → запись
 * назначения → переход заказа, история и событие. Всё в одной транзакции
 * (FR-DSP-135). Ручное назначение проходит те же проверки, что и подбор
 * (FR-DSP-133): недоступность, транспорт, зона и попадание в список кандидатов.
 *
 * Гонку двух диспетчеров отсекают два уровня: блокировка строки заказа и
 * частичные уникальные индексы активных назначений. Нарушение индекса
 * переводится в конфликт состояния, а не в 500 (FR-DSP-137).
 */
final readonly class AssignCourier
{
    /**
     * Тип агрегата исходящего события.
     */
    private const AGGREGATE_TYPE = 'order';

    /**
     * Тип события назначения курьера.
     */
    private const EVENT_ASSIGNED = 'order.assigned';

    public function __construct(
        private CandidateFinder $finder,
        private CourierSelection $selection,
        private OrderAggregateFactory $aggregates,
    ) {}

    /**
     * @param int|null $courierId конкретный курьер; null — автоматический подбор
     * @param AssignmentStrategy|null $strategy правило из запроса; null — значение конфигурации
     *
     * @throws ApiException заказ не в статусе «создан», курьер недоступен или не найден ни один кандидат
     */
    public function handle(
        DeliveryOrder $order,
        User $dispatcher,
        ?int $courierId,
        ?AssignmentStrategy $strategy,
    ): Assignment {
        return DB::transaction(function () use ($order, $dispatcher, $courierId, $strategy): Assignment {
            $locked = DeliveryOrder::query()
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Назначение возможно только из статуса «создан» (FR-DSP-131).
            if ($locked->status !== OrderStatus::Created) {
                throw ApiException::orderStatusTransitionNotAllowed($locked->status, OrderStatus::Assigned);
            }

            $rule = $this->selection->forName(
                $strategy === null ? (string) config('dispatch.strategy') : $strategy->value,
            );

            $candidate = $courierId === null
                ? $this->pick($locked, $rule)
                : $this->ensureAvailable($locked, $courierId, $rule);

            try {
                $assignment = $this->persist($locked, $candidate, $rule);
                $this->assignOrder($locked, $assignment, $dispatcher);
            } catch (QueryException $exception) {
                throw $this->conflict($exception);
            }

            return $assignment;
        });
    }

    /**
     * Победитель автоматического подбора: первый кандидат применённого правила.
     *
     * @throws ApiException кандидатов нет (FR-DSP-136)
     */
    private function pick(DeliveryOrder $order, CourierAssignmentStrategy $rule): CourierCandidate
    {
        $candidates = $this->finder->find($order, $rule, (int) config('dispatch.candidates_limit'));

        return $candidates[0] ?? throw ApiException::noCourierAvailable();
    }

    /**
     * Проверки ручного назначения по данным, а не по тексту ошибки.
     *
     * Сначала отсекается недоступность (нет профиля или смены, есть активное
     * назначение) — это 409. Затем несовместимость транспорта и зоны — 422.
     * Последним проверяется попадание в список кандидатов: так учитываются
     * вес и радиус посылки, которые не видны по отдельным полям курьера.
     *
     * @throws ApiException курьер недоступен или несовместим с заказом
     */
    private function ensureAvailable(
        DeliveryOrder $order,
        int $courierId,
        CourierAssignmentStrategy $rule,
    ): CourierCandidate {
        $courier = User::query()->with('courierProfile')->find($courierId);

        if ($courier === null || ! $courier->role->canHandleAssignments()) {
            throw ApiException::courierNotAvailable();
        }

        $profile = $courier->courierProfile;

        if ($profile === null || ! $profile->is_active) {
            throw ApiException::courierNotAvailable();
        }

        $shift = CourierShift::query()
            ->where('courier_id', $courierId)
            ->open()
            ->latest('started_at')
            ->first();

        if ($shift === null) {
            throw ApiException::courierNotAvailable();
        }

        $isBusy = Assignment::query()
            ->where('courier_id', $courierId)
            ->active()
            ->exists();

        if ($isBusy) {
            throw ApiException::courierNotAvailable();
        }

        if ($profile->transport_type !== $order->transport_type) {
            throw ApiException::courierTransportMismatch();
        }

        // Смена без зоны подходит для заказа любой зоны (FR-DSP-114).
        if ($shift->zone_id !== null && $shift->zone_id !== $order->zone_id) {
            throw ApiException::courierZoneMismatch();
        }

        foreach ($this->finder->find($order, $rule, PHP_INT_MAX) as $candidate) {
            if ($candidate->courierId() === $courierId) {
                return $candidate;
            }
        }

        // Курьер прошёл проверки, но не попал в список: посылка не проходит
        // по весу или радиусу транспорта (FR-DSP-115, 133).
        throw ApiException::courierNotAvailable();
    }

    /**
     * Запись попытки назначения: метрика выбора фиксируется в строке (FR-DSP-163).
     */
    private function persist(
        DeliveryOrder $order,
        CourierCandidate $candidate,
        CourierAssignmentStrategy $rule,
    ): Assignment {
        $assignment = new Assignment;

        $assignment->fill([
            'order_id' => $order->getKey(),
            'courier_id' => $candidate->courierId(),
            'strategy' => $rule->strategy(),
            'distance_to_pickup_km' => $candidate->distanceToPickupKm(),
        ]);

        // Единственное место, где меняется статус: «— → назначено».
        $assignment->applyTransition(AssignmentStatus::Assigned);

        return $assignment;
    }

    /**
     * Переход заказа, история и событие в той же транзакции (FR-DSP-135, FR-TRK-141).
     *
     * @throws ApiException переход статуса запрещён
     */
    private function assignOrder(DeliveryOrder $order, Assignment $assignment, User $dispatcher): void
    {
        $aggregate = $this->aggregates->fromModel($order);

        try {
            $transition = $aggregate->assign();
        } catch (OrderStatusTransitionNotAllowed $exception) {
            throw ApiException::orderStatusTransitionNotAllowed($exception->fromStatus(), $exception->toStatus());
        }

        $order->applyTransition($transition);

        OrderStatusHistory::query()->create([
            'order_id' => $order->getKey(),
            'from_status' => $transition->from(),
            'to_status' => $transition->to(),
            'changed_by' => $dispatcher->getKey(),
            'reason' => $transition->reason(),
        ]);

        // Персональных данных нет: только идентификаторы, статус и время (FR-TRK-142).
        $event = OrderEventPayload::build(
            order: $order,
            status: $assignment->status->value,
            occurredAt: $assignment->assigned_at,
            fields: [
                'assignment_id' => $assignment->getKey(),
                'courier_id' => (int) $assignment->courier_id,
                'assigned_at' => $assignment->assigned_at->format(DateTimeInterface::ATOM),
            ],
        );

        OutboxMessage::query()->create([
            'message_id' => $event['message_id'],
            'aggregate_type' => self::AGGREGATE_TYPE,
            'aggregate_id' => $order->getKey(),
            'event_type' => self::EVENT_ASSIGNED,
            'payload' => $event['payload'],
        ]);
    }

    /**
     * Нарушение частичного уникального индекса — конфликт состояния, а не 500.
     *
     * Параллельный запрос уже создал активное назначение заказа или курьера.
     * Для клиента заказ перестал быть «создан», поэтому используется тот же
     * код недопустимого перехода: отдельного кода ЧТЗ 8.8 не предусматривает.
     */
    private function conflict(QueryException $exception): ApiException
    {
        /** @var array<int, mixed> $errorInfo */
        $errorInfo = is_array($exception->errorInfo) ? $exception->errorInfo : [];
        $state = (string) ($errorInfo[0] ?? '');

        if ($state === '23505') {
            return ApiException::orderStatusTransitionNotAllowed(OrderStatus::Created, OrderStatus::Assigned);
        }

        throw $exception;
    }
}
