<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Domain\Events\EventEnvelope;
use App\Services\Notifications\OrderEventProcessor;

/**
 * Потребитель потока событий заказов (FR-NTF-204, FR-NTF-224, FR-NTF-225, FR-NTF-265).
 *
 * Поток заказов обслуживает эффект создания уведомлений; проход по потоку,
 * проверка конверта и подтверждение общие для обоих потоков и живут
 * в EventStreamConsumer. Поток контактов обслуживает ContactEventConsumer
 * (FR-NTF-231): у него свой эффект, но тот же порядок «Inbox → эффект → ack».
 */
final class OrderEventConsumer
{
    public function __construct(
        private readonly EventStreamConsumer $consumer,
        private readonly OrderEventProcessor $processor,
    ) {}

    /**
     * Один проход потребления потока заказов.
     */
    public function consume(string $streamName, string $consumerName, int $limit): ConsumeResult
    {
        return $this->consumer->consume(
            $streamName,
            $consumerName,
            $limit,
            fn (EventEnvelope $event): int => $this->processor->process($streamName, $consumerName, $event),
        );
    }
}
