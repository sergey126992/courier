<?php

declare(strict_types=1);

namespace App\Actions\Order;

use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Events\AggregateType;
use App\Domain\Events\EventType;
use App\Domain\Order\Exceptions\OrderStatusTransitionNotAllowed;
use App\Domain\Order\OrderStatus;
use App\Domain\Order\StatusTransition;
use App\Http\Problem\ApiException;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder as DeliveryOrderModel;
use App\Models\Order\OrderStatusHistory;
use App\Models\Order\OutboxMessage;
use App\Models\User;
use App\Services\Events\OrderEventPayload;
use App\Services\Order\OrderAggregateFactory;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Отмена заказа отправителем или диспетчером.
 *
 * Переход выполняет доменный агрегат: отмена после забора отсекается правилом
 * переходов, а не проверкой в этом действии. Автор и причина попадают в историю.
 */
final readonly class CancelOrder
{
    public function __construct(
        private OrderAggregateFactory $aggregates,
    ) {}

    /**
     * @throws ApiException переход недопустим (заказ уже забран или отменён)
     */
    public function handle(DeliveryOrderModel $order, User $author, ?string $reason): DeliveryOrderModel
    {
        return DB::transaction(function () use ($order, $author, $reason): DeliveryOrderModel {
            $aggregate = $this->aggregates->fromModel($order);

            try {
                $transition = $aggregate->cancel($reason);
            } catch (OrderStatusTransitionNotAllowed $exception) {
                throw ApiException::orderStatusTransitionNotAllowed($exception->fromStatus(), $exception->toStatus());
            }

            $this->record($order, $transition, $author);
            $this->closeActiveAssignment($order);
            $this->recordEvent($order, $author, $reason);

            return $order;
        });
    }

    /**
     * Отмена заказа закрывает активное назначение в той же транзакции (FR-DSP-139).
     *
     * Смена курьера при этом остаётся открытой: закрывает её только курьер.
     * Если активного назначения нет, отмена работает как раньше.
     */
    private function closeActiveAssignment(DeliveryOrderModel $order): void
    {
        $assignments = Assignment::query()
            ->where('order_id', $order->getKey())
            ->active()
            ->lockForUpdate()
            ->get();

        foreach ($assignments as $assignment) {
            $assignment->applyTransition(AssignmentStatus::Cancelled);
        }
    }

    /**
     * Записывает переход в модель и в историю: статус меняется только здесь.
     */
    private function record(DeliveryOrderModel $order, StatusTransition $transition, User $author): void
    {
        $order->applyTransition($transition);

        OrderStatusHistory::query()->create([
            'order_id' => $order->getKey(),
            'from_status' => $transition->from(),
            'to_status' => $transition->to(),
            'changed_by' => $author->getKey(),
            'reason' => $transition->reason(),
        ]);
    }

    /**
     * Пишет order.cancelled в транзакции отмены (FR-NTF-116).
     *
     * Персональных данных нет: только идентификаторы, роль автора, статус,
     * момент и код причины. Причина необязательна, поэтому reason_code может
     * быть null — поле всё равно присутствует в payload.
     */
    private function recordEvent(DeliveryOrderModel $order, User $author, ?string $reason): void
    {
        $cancelledAt = $order->cancelled_at ?? now();

        // Отмена получает только общий состав события заказа: политика
        // отправки по ней в M11 не меняется (FR-NTF-223, ЧТЗ 8.3 п. 4).
        $event = OrderEventPayload::build(
            order: $order,
            status: OrderStatus::Cancelled->value,
            occurredAt: $cancelledAt,
            fields: [
                'cancelled_by' => $author->getKey(),
                'cancelled_by_role' => $author->role->value,
                'cancelled_at' => $cancelledAt->format(DateTimeInterface::ATOM),
                'reason_code' => $reason,
            ],
        );

        OutboxMessage::query()->create([
            'message_id' => $event['message_id'],
            'aggregate_type' => AggregateType::Order->value,
            'aggregate_id' => $order->getKey(),
            'event_type' => EventType::OrderCancelled->value,
            'payload' => $event['payload'],
        ]);
    }
}
