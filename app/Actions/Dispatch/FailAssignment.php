<?php

declare(strict_types=1);

namespace App\Actions\Dispatch;

use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Dispatch\Exceptions\AssignmentTransitionNotAllowed;
use App\Domain\Dispatch\FailureReason;
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
 * Неудачная попытка вручения (FR-DSP-144, 148).
 *
 * Заказ переходит «забран» → «не выполнен», назначение — «забрано» →
 * «не выполнено». Причина из закрытого перечня и комментарий записываются в
 * назначение, код причины дублируется в историю статусов заказа (ЧТЗ 12.1).
 * Автор записи истории — курьер, выполнивший действие.
 *
 * Запись назначения, заказа, истории и события order.failed выполняется в
 * одной транзакции; событие не содержит персональных данных и текста
 * комментария (FR-TRK-142).
 */
final readonly class FailAssignment
{
    /**
     * Тип агрегата исходящего события.
     */
    private const AGGREGATE_TYPE = 'order';

    /**
     * Тип события неудачной попытки.
     */
    private const EVENT_FAILED = 'order.failed';

    public function __construct(
        private OrderAggregateFactory $aggregates,
    ) {}

    /**
     * @param string|null $comment необязательный комментарий курьера
     *
     * @throws ApiException назначение неактивно или переход статуса недопустим
     */
    public function handle(
        Assignment $assignment,
        User $courier,
        FailureReason $reason,
        ?string $comment,
    ): Assignment {
        return DB::transaction(function () use ($assignment, $courier, $reason, $comment): Assignment {
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
                // В историю заказа попадает код причины, а не её подпись (ЧТЗ 12.1).
                $transition = $aggregate->fail($reason->value);
            } catch (OrderStatusTransitionNotAllowed $exception) {
                throw ApiException::orderStatusTransitionNotAllowed(
                    $exception->fromStatus(),
                    $exception->toStatus(),
                );
            }

            $locked->failure_reason = $reason;
            $locked->failure_comment = $comment;

            $this->move($locked);
            $order->applyTransition($transition);

            OrderStatusHistory::query()->create([
                'order_id' => $order->getKey(),
                'from_status' => $transition->from(),
                'to_status' => $transition->to(),
                'changed_by' => $courier->getKey(),
                'reason' => $transition->reason(),
            ]);

            // Код причины из закрытого перечня: подпись и комментарий не передаются.
            $event = OrderEventPayload::build(
                order: $order,
                status: $transition->to()->value,
                occurredAt: $locked->failed_at ?? now(),
                fields: [
                    'assignment_id' => $locked->getKey(),
                    'courier_id' => (int) $locked->courier_id,
                    'failure_reason' => $reason->value,
                    'failed_at' => $locked->failed_at?->format(DateTimeInterface::ATOM),
                ],
            );

            OutboxMessage::query()->create([
                'message_id' => $event['message_id'],
                'aggregate_type' => self::AGGREGATE_TYPE,
                'aggregate_id' => $order->getKey(),
                'event_type' => self::EVENT_FAILED,
                'payload' => $event['payload'],
            ]);

            return $locked;
        });
    }

    /**
     * Переход назначения: причина и комментарий сохраняются вместе со статусом.
     *
     * @throws ApiException пара статусов назначения рассогласована со статусом заказа
     */
    private function move(Assignment $assignment): void
    {
        try {
            $assignment->applyTransition(AssignmentStatus::Failed);
        } catch (AssignmentTransitionNotAllowed) {
            throw ApiException::assignmentNotActive();
        }
    }
}
