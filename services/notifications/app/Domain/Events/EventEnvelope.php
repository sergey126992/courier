<?php

declare(strict_types=1);

namespace App\Domain\Events;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Конверт события в сервисе (FR-NTF-223, FR-NTF-209).
 *
 * Собирается из полей сообщения потока после проверки контракта: адаптер
 * не добавляет и не переименовывает поля. Персональных данных в конверте
 * событий заказа нет (FR-NTF-223).
 */
final readonly class EventEnvelope
{
    /**
     * @param array<string, mixed> $payload объект payload контракта
     */
    public function __construct(
        public string $messageId,
        public EventType $eventType,
        public string $schemaVersion,
        public array $payload,
        public ?string $correlationId = null,
        public ?string $occurredAt = null,
        public int $aggregateId = 0,
    ) {}

    /**
     * Ключ дедупликации: `event_id` из payload, а при его отсутствии — message_id.
     *
     * По ЧТЗ 8.3 п. 1 `event_id` равен `message_id` строки Outbox: повторная
     * публикация сохраняет идентификатор, поэтому дедупликация работает и
     * при повторе Outbox (FR-NTF-262).
     */
    public function eventId(): string
    {
        $eventId = $this->payload['event_id'] ?? null;

        return is_string($eventId) && trim($eventId) !== '' ? $eventId : $this->messageId;
    }

    /**
     * Целое поле payload; отсутствующее или нечисловое значение — ноль.
     */
    public function payloadInt(string $key): int
    {
        $value = $this->payload[$key] ?? null;

        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : 0);
    }

    /**
     * Строковое поле payload; иное значение — null.
     */
    public function payloadString(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Момент события в UTC; незаполненный или некорректный — текущий момент.
     */
    public function occurredAtUtc(): DateTimeImmutable
    {
        try {
            $moment = $this->occurredAt === null || $this->occurredAt === ''
                ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
                : new DateTimeImmutable($this->occurredAt);

            return $moment->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return new DateTimeImmutable('now', new DateTimeZone('UTC'));
        }
    }
}
