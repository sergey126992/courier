<?php

declare(strict_types=1);

namespace App\Actions\Dispatch;

use App\Domain\Dispatch\CourierCandidates;
use App\Domain\Dispatch\CourierSelection;
use App\Domain\Dispatch\Exceptions\UnknownAssignmentStrategy;
use App\Domain\Order\OrderStatus;
use App\Http\Problem\ApiException;
use App\Models\Order\DeliveryOrder;
use App\Services\Dispatch\CandidateFinder;

/**
 * Подбор кандидатов на заказ для диспетчера (FR-DSP-111…118).
 *
 * Действие проверяет статус заказа, выбирает правило (запрос или настройка)
 * и отдаёт упорядоченный список. Само назначение — отдельная операция.
 */
final readonly class FindCouriers
{
    public function __construct(
        private CandidateFinder $finder,
        private CourierSelection $selection,
    ) {}

    /**
     * @param string|null $strategy имя правила из запроса; null — значение конфигурации
     *
     * @throws ApiException заказ уже не в статусе «создан» (FR-DSP-118)
     * @throws UnknownAssignmentStrategy правила с таким именем нет в настройке (FR-DSP-125)
     */
    public function handle(DeliveryOrder $order, ?string $strategy): CourierCandidates
    {
        if ($order->status !== OrderStatus::Created) {
            throw ApiException::orderStatusTransitionNotAllowed($order->status, OrderStatus::Assigned);
        }

        $rule = $this->selection->forName($strategy ?? (string) config('dispatch.strategy'));

        return new CourierCandidates(
            strategy: $rule->strategy(),
            orderEtaMinutes: $order->eta_minutes,
            candidates: $this->finder->find($order, $rule, (int) config('dispatch.candidates_limit')),
        );
    }
}
