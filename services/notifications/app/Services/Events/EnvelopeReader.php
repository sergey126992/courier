<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Domain\Events\EventEnvelope;
use App\Domain\Events\EventType;
use App\Domain\Notifications\NotificationErrorCode;
use App\Services\Events\Exceptions\EnvelopeInvalid;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Конверт события из записи потока (FR-NTF-223, FR-NTF-224, FR-NTF-225).
 *
 * Восстанавливает конверт вместе с payload, проверяет его по общей JSON
 * Schema и отдаёт домену готовый объект. Проверка идёт до эффекта: невалидное
 * сообщение помечается `invalid` и подтверждается без изменений в базе.
 * Чтение общее для потоков заказов и контактов: контракт один, отличается
 * только тип события. Персональные данные контактов в журнал не попадают,
 * потому что тела сообщений здесь не логируются (FR-NTF-232).
 */
final class EnvelopeReader
{
    public function __construct(private readonly EventContractValidator $validator) {}

    /**
     * Восстанавливает конверт из полей записи и проверяет контракт.
     *
     * @throws EnvelopeInvalid тип, версия или payload не приняты
     */
    public function read(StreamMessage $message): EventEnvelope
    {
        $eventType = EventType::tryFrom($message->field('event_type'));

        if ($eventType === null) {
            throw new EnvelopeInvalid(
                NotificationErrorCode::CONTRACT_UNKNOWN,
                'Тип события не входит в реестр сервиса.',
            );
        }

        $schemaVersion = $message->field('schema_version');

        if ($schemaVersion !== (string) config('notifications.contracts.schema_version')) {
            throw new EnvelopeInvalid(
                NotificationErrorCode::CONTRACT_UNKNOWN,
                'Версия контракта события неизвестна.',
            );
        }

        $messageId = $message->field('message_id');

        if (! Str::isUuid($messageId)) {
            throw new EnvelopeInvalid(
                NotificationErrorCode::CONTRACT_INVALID,
                'Конверт события не содержит идентификатора.',
            );
        }

        $payload = json_decode($message->field('payload'), true);

        if (! is_array($payload)) {
            throw new EnvelopeInvalid(
                NotificationErrorCode::CONTRACT_INVALID,
                'Payload события не является объектом.',
            );
        }

        /** @var array<string, mixed> $payload */
        $document = $this->document($message, $eventType, $schemaVersion, $messageId, $payload);
        $errorCode = $this->validator->validate($eventType->value, $schemaVersion, $document);

        if ($errorCode !== null) {
            throw new EnvelopeInvalid($errorCode, 'Конверт события не соответствует схеме.');
        }

        $correlationId = $message->field('correlation_id');

        return new EventEnvelope(
            messageId: $messageId,
            eventType: $eventType,
            schemaVersion: $schemaVersion,
            payload: $payload,
            correlationId: $correlationId === '' ? null : $correlationId,
            occurredAt: $message->field('occurred_at'),
            aggregateId: (int) $message->field('aggregate_id'),
        );
    }

    /**
     * Идентификатор недействительного сообщения для отметки в Inbox.
     *
     * У конверта без корректного message_id берётся детерминированный
     * идентификатор от записи потока: повторная выдача того же сообщения
     * не создаёт вторую отметку.
     */
    public function invalidMessageId(StreamMessage $message): string
    {
        $declared = $message->field('message_id');

        if (Str::isUuid($declared)) {
            return $declared;
        }

        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'notifications:invalid:'.$message->id)->toString();
    }

    /**
     * Конверт события в форме JSON Schema (ЧТЗ 8.4).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function document(
        StreamMessage $message,
        EventType $eventType,
        string $schemaVersion,
        string $messageId,
        array $payload,
    ): array {
        $document = [
            'message_id' => $messageId,
            'event_type' => $eventType->value,
            'schema_version' => $schemaVersion,
            'occurred_at' => $message->field('occurred_at'),
            'aggregate_type' => $message->field('aggregate_type'),
            'aggregate_id' => (int) $message->field('aggregate_id'),
            'payload' => $payload,
        ];

        $correlationId = $message->field('correlation_id');

        if ($correlationId !== '') {
            $document['correlation_id'] = $correlationId;
        }

        return $document;
    }
}
