<?php

declare(strict_types=1);

namespace App\Actions\Dispatch;

use App\Domain\Dispatch\AssignmentStrategy;
use App\Domain\Order\Exceptions\OrderStatusTransitionNotAllowed;
use App\Domain\Order\OrderStatus;
use App\Http\Problem\ApiException;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OrderStatusHistory;
use App\Models\User;
use App\Services\Order\OrderAggregateFactory;
use Illuminate\Support\Facades\DB;

/**
 * Переназначение заказа после неудачной попытки (FR-DSP-138).
 *
 * Прежняя строка назначения не меняется: она остаётся в истории со статусом
 * «не выполнено» и причиной. Заказ возвращается в «создан», после чего
 * выполняется обычное назначение, создающее новую попытку.
 */
final readonly class ReassignOrder
{
    public function __construct(
        private OrderAggregateFactory $aggregates,
        private AssignCourier $assign,
    ) {}

    /**
     * @throws ApiException заказ не в статусе «не выполнен», курьер недоступен или кандидатов нет
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

            // Новая попытка возможна только после неудачи.
            if ($locked->status !== OrderStatus::Failed) {
                throw ApiException::orderStatusTransitionNotAllowed($locked->status, OrderStatus::Created);
            }

            $aggregate = $this->aggregates->fromModel($locked);

            try {
                $transition = $aggregate->reassign();
            } catch (OrderStatusTransitionNotAllowed $exception) {
                throw ApiException::orderStatusTransitionNotAllowed($exception->fromStatus(), $exception->toStatus());
            }

            $locked->applyTransition($transition);

            // Возврат в работу — отдельная запись истории; следующая появится при назначении.
            OrderStatusHistory::query()->create([
                'order_id' => $locked->getKey(),
                'from_status' => $transition->from(),
                'to_status' => $transition->to(),
                'changed_by' => $dispatcher->getKey(),
                'reason' => $transition->reason(),
            ]);

            // Назначение идёт в этой же транзакции: вложенный вызов использует её уровень.
            return $this->assign->handle($locked, $dispatcher, $courierId, $strategy);
        });
    }
}
