<?php

declare(strict_types=1);

namespace App\Domain\Dispatch;

/**
 * Результат подбора: применённое правило, прогноз заказа и упорядоченные кандидаты.
 *
 * Ответ диспетчеру и последующее назначение получают и порядок, и фактически
 * применённое правило: клиент не должен догадываться, какое правило сработало,
 * если в запросе оно не указано (ЧТЗ 8.2, п. 2 и 6).
 */
final readonly class CourierCandidates
{
    /**
     * @param list<CourierCandidate> $candidates кандидаты в порядке предпочтения
     */
    public function __construct(
        private AssignmentStrategy $strategy,
        private int $orderEtaMinutes,
        private array $candidates,
    ) {}

    /**
     * Фактически применённое правило подбора.
     */
    public function strategy(): AssignmentStrategy
    {
        return $this->strategy;
    }

    /**
     * Прогноз доставки заказа, участвовавший в правиле «лучший ETA».
     */
    public function orderEtaMinutes(): int
    {
        return $this->orderEtaMinutes;
    }

    /**
     * Кандидаты в порядке предпочтения: первый — победитель подбора.
     *
     * @return list<CourierCandidate>
     */
    public function candidates(): array
    {
        return $this->candidates;
    }

    /**
     * Подбор не нашёл ни одного подходящего курьера.
     */
    public function isEmpty(): bool
    {
        return $this->candidates === [];
    }
}
