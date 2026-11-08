<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Domain\Events\EventEnvelope;
use App\Domain\Events\EventPayload;
use App\Domain\Events\EventSchemaVersion;
use App\Domain\Events\EventType;
use App\Services\Events\Amqp\AmqpBroker;
use App\Services\Events\Amqp\BrokerMessage;
use App\Services\Events\Contracts\EventBus;
use App\Services\Events\Exceptions\EventDeliveryFailed;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Шина событий монолита на RabbitMQ (FR-NTF-541…552).
 *
 * Реализует контракт `EventBus`: домен и Actions о транспорте не знают.
 * Публикация идёт в exchange типа `topic` с ключом по домену события;
 * подтверждение Outbox (`published_at`) ставится только после того, как
 * брокер принял сообщение. Потребление — `basic_get` без автоподтверждения,
 * `ack` только после успешного обработчика. Неподтверждённое сообщение
 * повторяется публикацией со счётчиком попыток, после предела уходит в DLX
 * через `basic_reject`.
 *
 * Недоступность брокера не роняет процесс: публикация пробрасывает
 * инфраструктурное исключение — публикатор Outbox повторит передачу
 * и не поставит `published_at` (FR-NTF-548). Параметры подключения и пароль
 * в журнал не попадают (FR-NTF-549).
 */
final class RabbitMqEventBus implements EventBus
{
    /**
     * Код журнала при неизвестном контракте сообщения.
     */
    private const CONTRACT_UNKNOWN_CODE = 'EVENT_CONTRACT_UNKNOWN';

    /**
     * Код журнала при отказе обработчика.
     */
    private const CONSUMER_FAILED_CODE = 'EVENT_CONSUMER_FAILED';

    /**
     * @var array<string, array{fields: array<string, string>, headers: array<string, mixed>,
     *                          routing_key: string, received_at: float, stream: string, group: string}>
     */
    private array $pending = [];

    /**
     * @var array<string, bool>
     */
    private array $declared = [];

    public function __construct(
        private readonly AmqpBroker $broker,
        private readonly RabbitMqTopology $topology,
        private readonly int $pendingIdleSeconds = 60,
    ) {
        if ($pendingIdleSeconds < 0) {
            throw new InvalidArgumentException('Задержка повторной выдачи не может быть отрицательной.');
        }
    }

    public function publish(EventEnvelope $event): void
    {
        $fields = $this->fields($event);
        $routingKey = $this->topology->routingKey($fields);

        if ($routingKey === null) {
            throw new InvalidArgumentException(sprintf(
                'Тип события «%s» не имеет домена маршрутизации брокера.',
                $event->eventType->value,
            ));
        }

        if (! $this->broker->ensureExchange($this->topology->exchange())) {
            throw EventDeliveryFailed::make($event->messageId, []);
        }

        $published = $this->broker->publish(
            $this->topology->exchange(),
            $routingKey,
            $fields,
            $this->topology->messageTtlMilliseconds(),
            ['x-attempt' => 0],
        );

        if (! $published) {
            throw EventDeliveryFailed::make($event->messageId, []);
        }
    }

    public function consume(string $stream, string $group, string $consumer, int $limit, callable $handler): ConsumeResult
    {
        $messages = $this->read($stream, $group, $limit);

        if ($messages === null) {
            return ConsumeResult::degraded();
        }

        if ($messages !== []) {
            return $this->deliver($stream, $group, $messages, $handler);
        }

        return $this->reclaim($stream, $group, $consumer, $this->pendingIdleSeconds, $limit, $handler);
    }

    public function ack(string $stream, string $group, string $messageId): void
    {
        if ($this->broker->ack($messageId)) {
            unset($this->pending[$messageId]);
        }
    }

    public function reclaim(string $stream, string $group, string $consumer, int $idleSeconds, int $limit, callable $handler): ConsumeResult
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Размер пачки потребления должен быть положительным.');
        }

        if (! $this->broker->isConnected()) {
            return ConsumeResult::degraded();
        }

        $now = microtime(true);
        $batch = [];

        foreach ($this->pending as $id => $item) {
            if ($item['stream'] !== $stream || $item['group'] !== $group) {
                continue;
            }

            if ($now - $item['received_at'] < max(0, $idleSeconds)) {
                continue;
            }

            $this->pending[$id]['received_at'] = $now;
            $batch[(string) $id] = $item['fields'];

            if (count($batch) >= $limit) {
                break;
            }
        }

        if ($batch === []) {
            return ConsumeResult::empty();
        }

        return $this->deliver($stream, $group, $batch, $handler);
    }

    /**
     * Читает пачку без подтверждения; `null` — брокер недоступен.
     *
     * @return array<string, array<string, string>>|null
     */
    private function read(string $stream, string $group, int $limit): ?array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Размер пачки потребления должен быть положительным.');
        }

        $queue = $this->topology->queueName($stream, $group);

        if (! $this->declare($queue)) {
            return null;
        }

        $this->settle($stream, $group, $queue);

        $messages = $this->broker->read($queue, $limit);

        if ($messages === null) {
            return null;
        }

        $result = [];

        foreach ($messages as $message) {
            $this->remember($stream, $group, $queue, $message);
            $result[$message->id] = $message->fields;
        }

        return $result;
    }

    /**
     * Повторяет неподтверждённые сообщения или отправляет их в DLX.
     */
    private function settle(string $stream, string $group, string $queue): void
    {
        foreach ($this->pending as $id => $item) {
            if ($item['stream'] !== $stream || $item['group'] !== $group) {
                continue;
            }

            $attempts = (int) ($item['headers']['x-attempt'] ?? 0) + 1;

            if ($attempts >= $this->topology->maxAttempts()) {
                $this->broker->reject((string) $id, false);
                unset($this->pending[$id]);

                continue;
            }

            $published = $this->broker->publish(
                $this->topology->exchange(),
                $item['routing_key'],
                $item['fields'],
                $this->topology->messageTtlMilliseconds(),
                ['x-attempt' => $attempts],
            );

            if ($published && $this->broker->ack((string) $id)) {
                unset($this->pending[$id]);
            }
        }
    }

    /**
     * Вызывает обработчик и подтверждает сообщение только после успеха.
     *
     * @param array<string, array<string, string>> $messages
     * @param callable(EventEnvelope, string): void $handler
     */
    private function deliver(string $stream, string $group, array $messages, callable $handler): ConsumeResult
    {
        $messageIds = [];
        $processed = 0;
        $acknowledged = 0;
        $failed = 0;
        $invalid = 0;

        foreach ($messages as $id => $fields) {
            $messageId = (string) $id;
            $messageIds[] = $messageId;

            $envelope = $this->envelopeFrom($messageId, $fields);

            if ($envelope === null) {
                $invalid++;

                if ($this->acknowledge($messageId)) {
                    $acknowledged++;
                }

                continue;
            }

            try {
                $handler($envelope, $messageId);
            } catch (Throwable $exception) {
                $failed++;

                Log::error('Обработчик не принял событие; сообщение будет выдано повторно.', [
                    'code' => self::CONSUMER_FAILED_CODE,
                    'message_id' => $messageId,
                    'event_type' => $envelope->eventType->value,
                    'exception' => $exception::class,
                ]);

                continue;
            }

            if (! $this->acknowledge($messageId)) {
                $failed++;

                continue;
            }

            $processed++;
            $acknowledged++;
        }

        return new ConsumeResult(
            read: count($messageIds),
            processed: $processed,
            acknowledged: $acknowledged,
            failed: $failed,
            invalid: $invalid,
            messageIds: $messageIds,
        );
    }

    private function acknowledge(string $messageId): bool
    {
        if (! $this->broker->ack($messageId)) {
            return false;
        }

        unset($this->pending[$messageId]);

        return true;
    }

    /**
     * Восстанавливает конверт из полей сообщения; неизвестный контракт — null.
     *
     * @param array<string, mixed> $fields
     */
    private function envelopeFrom(string $messageId, array $fields): ?EventEnvelope
    {
        try {
            $eventType = EventType::from((string) ($fields['event_type'] ?? ''));
            $version = EventSchemaVersion::from((string) ($fields['schema_version'] ?? ''));
            $payload = json_decode((string) ($fields['payload'] ?? ''), true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($payload)) {
                throw new InvalidArgumentException('Payload события не является объектом.');
            }

            /** @var array<string, mixed> $payload */
            $correlationId = $fields['correlation_id'] ?? null;

            return new EventEnvelope(
                messageId: (string) ($fields['message_id'] ?? $messageId),
                eventType: $eventType,
                aggregateId: (int) ($fields['aggregate_id'] ?? 0),
                occurredAt: new DateTimeImmutable((string) ($fields['occurred_at'] ?? 'now')),
                payload: EventPayload::fromArray($payload),
                schemaVersion: $version,
                correlationId: is_string($correlationId) && $correlationId !== '' ? $correlationId : null,
            );
        } catch (Throwable $exception) {
            Log::warning('Сообщение с неизвестным или некорректным контрактом пропущено.', [
                'code' => self::CONTRACT_UNKNOWN_CODE,
                'message_id' => $messageId,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    /**
     * Поля сообщения: конверт без добавления и переименования полей (FR-NTF-209).
     *
     * @return array<string, string>
     */
    private function fields(EventEnvelope $event): array
    {
        $fields = [];

        foreach ($event->toArray() as $name => $value) {
            if ($value === null) {
                continue;
            }

            $fields[(string) $name] = is_array($value)
                ? (string) json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                : (string) $value;
        }

        return $fields;
    }

    private function declare(string $queue): bool
    {
        if (($this->declared[$queue] ?? false) === true) {
            return true;
        }

        if (! $this->topology->ensure($this->broker, $queue)) {
            return false;
        }

        $this->declared[$queue] = true;

        return true;
    }

    private function remember(string $stream, string $group, string $queue, BrokerMessage $message): void
    {
        $this->pending[$message->id] = [
            'fields' => $message->fields,
            'headers' => $message->headers,
            'routing_key' => $message->routingKey,
            'received_at' => microtime(true),
            'stream' => $stream,
            'group' => $group,
        ];
    }
}
