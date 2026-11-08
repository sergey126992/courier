<?php

declare(strict_types=1);

namespace App\Actions\Dispatch;

use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Dispatch\Exceptions\AssignmentTransitionNotAllowed;
use App\Domain\Order\Exceptions\OrderStatusTransitionNotAllowed;
use App\Http\Problem\ApiException;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OrderStatusHistory;
use App\Models\Order\OutboxMessage;
use App\Models\User;
use App\Services\Events\OrderEventPayload;
use App\Services\Order\OrderAggregateFactory;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Забор посылки курьером (FR-DSP-142).
 *
 * Заказ переходит «принят» → «забран», назначение — «принято» → «забрано».
 * Статусы назначения, заказа, запись истории и событие order.picked_up
 * выполняются в одной транзакции (FR-DSP-135, FR-DSP-149).
 *
 * Событие не содержит персональных данных: только идентификаторы, статус и
 * время этапа (FR-TRK-142).
 */
final readonly class PickUpAssignment
{
    /**
     * Тип агрегата исходящего события.
     */
    private const AGGREGATE_TYPE = 'order';

    /**
     * Тип события забора посылки.
     */
    private const EVENT_PICKED_UP = 'order.picked_up';

    public function __construct(
        private OrderAggregateFactory $aggregates,
    ) {}

    /**
     * @throws ApiException назначение неактивно или переход статуса недопустим
     */
    public function handle(Assignment $assignment, User $courier): Assignment
    {
        return DB::transaction(function () use ($assignment, $courier): Assignment {
            $locked = Assignment::query()
                ->whereKey($assignment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Действие по завершённому назначению — конфликт состояния (FR-DSP-147).
            if ($locked->status->isTerminal()) {
                throw ApiException::assignmentNotActive();
            }

            $order = DeliveryOrder::query()
                ->whereKey($locked->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            $aggregate = $this->aggregates->fromModel($order);

            try {
                $transition = $aggregate->pickup();
            } catch (OrderStatusTransitionNotAllowed $exception) {
                throw ApiException::orderStatusTransitionNotAllowed(
                    $exception->fromStatus(),
                    $exception->toStatus(),
                );
            }

            $this->move($locked);
            $order->applyTransition($transition);

            OrderStatusHistory::query()->create([
                'order_id' => $order->getKey(),
                'from_status' => $transition->from(),
                'to_status' => $transition->to(),
                'changed_by' => $courier->getKey(),
                'reason' => $transition->reason(),
            ]);

            // Персональных данных нет: только идентификаторы, статус и время.
            $event = OrderEventPayload::build(
                order: $order,
                status: $transition->to()->value,
                occurredAt: $locked->picked_up_at ?? now(),
                fields: [
                    'assignment_id' => $locked->getKey(),
                    'courier_id' => (int) $locked->courier_id,
                    'picked_up_at' => $locked->picked_up_at?->format(DateTimeInterface::ATOM),
                ],
            );

            OutboxMessage::query()->create([
                'message_id' => $event['message_id'],
                'aggregate_type' => self::AGGREGATE_TYPE,
                'aggregate_id' => $order->getKey(),
                'event_type' => self::EVENT_PICKED_UP,
                'payload' => $event['payload'],
            ]);

            return $locked;
        });
    }

    /**
     * Переход назначения: статус меняет только модель через таблицу переходов.
     *
     * @throws ApiException пара статусов назначения рассогласована со статусом заказа
     */
    private function move(Assignment $assignment): void
    {
        try {
            $assignment->applyTransition(AssignmentStatus::PickedUp);
        } catch (AssignmentTransitionNotAllowed) {
            throw ApiException::assignmentNotActive();
        }
    }
}
