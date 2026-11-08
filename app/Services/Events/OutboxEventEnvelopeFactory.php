<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Domain\Events\EventEnvelope;
use App\Domain\Events\EventPayload;
use App\Domain\Events\EventTypeRegistry;
use App\Domain\Events\Exceptions\EventPayloadInvalid;
use App\Domain\Events\Exceptions\EventTypeUnknown;
use App\Models\Order\OutboxMessage;

/**
 * Единственная точка построения конверта события из строки Outbox.
 *
 * Домен не знает про Eloquent, поэтому перенос полей модели в конверт
 * выполняется здесь: публикатор и слушатели получают уже проверенный контракт.
 * Сквозной correlation_id строки Outbox переносится в конверт без изменений
 * (FR-OPS-201).
 */
final readonly class OutboxEventEnvelopeFactory
{
    public function __construct(
        private EventTypeRegistry $registry,
    ) {}

    /**
     * @throws EventTypeUnknown тип события не зарегистрирован в реестре
     * @throws EventPayloadInvalid в payload нет обязательного поля
     */
    public function fromMessage(OutboxMessage $message): EventEnvelope
    {
        return new EventEnvelope(
            messageId: $message->message_id,
            eventType: $this->registry->get($message->event_type),
            aggregateId: (int) $message->aggregate_id,
            occurredAt: $message->created_at->toDateTimeImmutable(),
            payload: EventPayload::fromArray($message->payload),
            schemaVersion: $this->registry->schemaVersion(),
            // У исторических строк correlation_id нет: конверт допускает null.
            correlationId: $message->correlation_id,
        );
    }
}
