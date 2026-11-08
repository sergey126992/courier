<?php

declare(strict_types=1);

namespace App\Domain\Events;

use App\Domain\Events\Exceptions\EventPayloadInvalid;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Неизменяемый конверт события, который получают слушатели (FR-NTF-113).
 *
 * Собирается только из зафиксированной строки Outbox: прикладной слой
 * отвечает за чтение модели, домен — за форму и проверку контракта.
 *
 * Сквозной correlation_id приходит из строки Outbox и передаётся в конверте
 * без изменений (FR-OPS-201, ЧТЗ 8.3).
 */
final readonly class EventEnvelope
{
    /**
     * Владелец агрегата выводится из типа события: рассогласованных конвертов нет.
     */
    public AggregateType $aggregateType;

    /**
     * @throws EventPayloadInvalid в payload нет обязательного поля
     */
    public function __construct(
        public string $messageId,
        public EventType $eventType,
        public int $aggregateId,
        public DateTimeImmutable $occurredAt,
        public EventPayload $payload,
        public EventSchemaVersion $schemaVersion = EventSchemaVersion::V1,
        /**
         * Сквозной идентификатор запроса монолита (FR-OPS-201).
         *
         * У исторических строк Outbox его нет: конверт допускает null,
         * поэтому публикатор не падает на старых записях.
         */
        public ?string $correlationId = null,
    ) {
        $this->aggregateType = $eventType->aggregateType();

        $missing = $payload->missing($eventType->requiredPayloadFields());

        if ($missing !== []) {
            throw EventPayloadInvalid::forMissingFields($eventType, $missing);
        }
    }

    /**
     * Форма конверта для шины и внешнего сервиса (ЧТЗ 8.4).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId,
            'event_type' => $this->eventType->value,
            'schema_version' => $this->schemaVersion->value,
            'correlation_id' => $this->correlationId,
            'occurred_at' => $this->occurredAt->format(DateTimeInterface::ATOM),
            'aggregate_type' => $this->aggregateType->value,
            'aggregate_id' => $this->aggregateId,
            'payload' => $this->payload->toArray(),
        ];
    }
}
