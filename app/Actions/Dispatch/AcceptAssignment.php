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
use App\Models\User;
use App\Services\Order\OrderAggregateFactory;
use Illuminate\Support\Facades\DB;

/**
 * Принятие назначения курьером (FR-DSP-141).
 *
 * Заказ переходит «назначен» → «принят», назначение — «назначено» → «принято».
 * Порядок: блокировка назначения → проверка активности → блокировка заказа →
 * переход домена → запись статуса назначения, заказа и истории в одной
 * транзакции.
 *
 * Терминальное назначение отсекается кодом ASSIGNMENT_NOT_ACTIVE (FR-DSP-147).
 * Повтор уже применённого действия упирается в переход заказа и даёт
 * ORDER_STATUS_TRANSITION_NOT_ALLOWED без второй записи истории (FR-DSP-149).
 *
 * Автор записи истории — курьер, выполнивший действие (FR-DSP-148).
 */
final readonly class AcceptAssignment
{
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
                $transition = $aggregate->accept();
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
            $assignment->applyTransition(AssignmentStatus::Accepted);
        } catch (AssignmentTransitionNotAllowed) {
            throw ApiException::assignmentNotActive();
        }
    }
}
