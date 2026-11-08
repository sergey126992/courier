<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Domain\Events\EventEnvelope;
use App\Domain\Events\EventPayload;
use App\Domain\Events\EventSchemaVersion;
use App\Domain\Events\EventType;
use App\Services\Events\Contracts\EventBus;
use DateTimeImmutable;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Транспорт событий на Redis Streams (FR-NTF-202…210).
 *
 * Публикация — XADD в поток, потребление — XREADGROUP пачкой, подтверждение —
 * XACK только после успешной фиксации эффекта, возврат «зависших» сообщений —
 * XAUTOCLAIM после pending_idle_seconds. Имена потоков, группы, потребителя,
 * размер пачки и задержка приходят из конфигурации: литералов в коде нет
 * (FR-OPS-211, FR-OPS-213).
 *
 * Ошибка доступа к Redis не роняет процесс: потребление возвращает degraded
 * результат и пишет структурированный журнал (FR-NTF-208). Публикация, наоборот,
 * пробрасывает исключение: публикатор Outbox повторит передачу с backoff
 * и не поставит published_at (FR-NTF-203).
 *
 * Redis не источник истины (FR-NTF-210): удаление потока означает лишь
 * отсутствие подтверждения для Outbox.
 */
final readonly class RedisStreamEventBus implements EventBus
{
    /**
     * Признак существующей consumer group в ответе Redis на XGROUP CREATE.
     */
    private const GROUP_EXISTS_MARKER = 'BUSYGROUP';

    /**
     * Код журнала при недоступности Redis.
     */
    private const TRANSPORT_UNAVAILABLE_CODE = 'EVENT_TRANSPORT_UNAVAILABLE';

    /**
     * Код журнала при неизвестном контракте сообщения (ЧТЗ 8.4).
     */
    private const CONTRACT_UNKNOWN_CODE = 'EVENT_CONTRACT_UNKNOWN';

    /**
     * Код журнала при отказе обработчика.
     */
    private const CONSUMER_FAILED_CODE = 'EVENT_CONSUMER_FAILED';

    /**
     * Код журнала при неудачном подтверждении.
     */
    private const ACK_FAILED_CODE = 'EVENT_ACK_FAILED';

    /**
     * @param string $stream поток, в который публикуются события приложения
     * @param string $prefix префикс имён потоков: изолирует окружения на общем Redis
     * @param int $pendingIdleSeconds задержка повторной выдачи неподтверждённых сообщений
     */
    public function __construct(
        private Connection $redis,
        private string $stream,
        private string $prefix = '',
        private int $pendingIdleSeconds = 60,
        /**
         * Маршрутизация по типу события (FR-NTF-231): контакты уходят в свой
         * поток, заказы — в общий. Без маршрутизатора используется $stream.
         */
        private ?EventStreamRouter $router = null,
    ) {
        if ($pendingIdleSeconds < 0) {
            throw new InvalidArgumentException('Задержка повторной выдачи не может быть отрицательной.');
        }
    }

    public function publish(EventEnvelope $event): void
    {
        // Поток выбирается по типу события: контакты не смешиваются с заказами
        // (FR-NTF-231, FR-NTF-232). Без маршрутизатора поток остаётся прежним.
        $stream = $this->router?->streamFor($event->eventType) ?? $this->stream;

        $this->execute(['XADD', $this->key($stream), '*', ...$this->fields($event)]);
    }

    public function consume(string $stream, string $group, string $consumer, int $limit, callable $handler): ConsumeResult
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Размер пачки потребления должен быть положительным.');
        }

        $key = $this->key($stream);

        if (! $this->ensureGroup($key, $group)) {
            return ConsumeResult::degraded();
        }

        $messages = $this->readNew($key, $group, $consumer, $limit);

        if ($messages === null) {
            return ConsumeResult::degraded();
        }

        if ($messages !== []) {
            return $this->deliver($key, $group, $messages, $handler);
        }

        // Новых сообщений нет: возвращаем в работу те, что ждут дольше pending_idle_seconds.
        return $this->reclaim($stream, $group, $consumer, $this->pendingIdleSeconds, $limit, $handler);
    }

    public function ack(string $stream, string $group, string $messageId): void
    {
        $this->execute(['XACK', $this->key($stream), $group, $messageId]);
    }

    public function reclaim(string $stream, string $group, string $consumer, int $idleSeconds, int $limit, callable $handler): ConsumeResult
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Размер пачки потребления должен быть положительным.');
        }

        $key = $this->key($stream);

        try {
            $response = $this->execute([
                'XAUTOCLAIM', $key, $group, $consumer,
                (string) max(0, $idleSeconds), '0-0',
                'COUNT', (string) $limit,
            ]);
        } catch (Throwable $exception) {
            $this->logUnavailable('XAUTOCLAIM', $exception);

            return ConsumeResult::degraded();
        }

        $messages = $this->claimedMessages($response);

        if ($messages === []) {
            return ConsumeResult::empty();
        }

        return $this->deliver($key, $group, $messages, $handler);
    }

    /**
     * Создаёт consumer group идемпотентно: существующая группа — не ошибка.
     */
    private function ensureGroup(string $key, string $group): bool
    {
        try {
            // «0» — группа читает поток с начала: событие не теряется, если
            // группа создана после первой публикации.
            $this->execute(['XGROUP', 'CREATE', $key, $group, '0', 'MKSTREAM']);
        } catch (Throwable $exception) {
            if (str_contains($exception->getMessage(), self::GROUP_EXISTS_MARKER)) {
                return true;
            }

            $this->logUnavailable('XGROUP CREATE', $exception);

            return false;
        }

        return true;
    }

    /**
     * Читает новые сообщения потока для группы.
     *
     * @return array<string, array<string, string>>|null null — Redis недоступен
     */
    private function readNew(string $key, string $group, string $consumer, int $limit): ?array
    {
        try {
            $response = $this->execute([
                'XREADGROUP', 'GROUP', $group, $consumer,
                'COUNT', (string) $limit,
                'STREAMS', $key, '>',
            ]);
        } catch (Throwable $exception) {
            $this->logUnavailable('XREADGROUP', $exception);

            return null;
        }

        if (! is_array($response)) {
            return [];
        }

        $messages = [];

        // Ответ XREADGROUP — список пар «поток → записи»: сырой ответ Predis
        // не разбирается, поэтому записи читаются как [идентификатор, поля].
        foreach ($response as $streamReply) {
            if (! is_array($streamReply)) {
                continue;
            }

            $entries = $streamReply[1] ?? null;

            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $id = $entry[0] ?? null;
                $fields = $entry[1] ?? null;

                if (! is_scalar($id) || ! is_array($fields)) {
                    continue;
                }

                $messages[(string) $id] = $this->normalizeFields($fields);
            }
        }

        return $messages;
    }

    /**
     * Приводит поля записи к карте «имя → значение».
     *
     * Сырой ответ Predis отдаёт поля плоским списком «имя, значение, …»;
     * разобранный ответ клиента уже приходит картой.
     *
     * @param array<int|string, mixed> $fields
     * @return array<string, string>
     */
    private function normalizeFields(array $fields): array
    {
        $normalized = [];

        if (! array_is_list($fields)) {
            foreach ($fields as $name => $value) {
                $normalized[(string) $name] = is_scalar($value) ? (string) $value : '';
            }

            return $normalized;
        }

        for ($index = 0; $index + 1 < count($fields); $index += 2) {
            $name = $fields[$index];

            if (! is_scalar($name)) {
                continue;
            }

            $value = $fields[$index + 1];
            $normalized[(string) $name] = is_scalar($value) ? (string) $value : '';
        }

        return $normalized;
    }

    /**
     * Разбирает ответ XAUTOCLAIM: [следующий id, список [id, поля], удалённые].
     *
     * @return array<string, array<string, string>>
     */
    private function claimedMessages(mixed $response): array
    {
        if (! is_array($response) || ! isset($response[1]) || ! is_array($response[1])) {
            return [];
        }

        $messages = [];

        foreach ($response[1] as $entry) {
            if (! is_array($entry) || ! array_key_exists(0, $entry) || ! array_key_exists(1, $entry)) {
                continue;
            }

            $id = $entry[0];
            $fields = $entry[1];

            if (! is_scalar($id) || ! is_array($fields)) {
                continue;
            }

            $messages[(string) $id] = $this->normalizeFields($fields);
        }

        return $messages;
    }

    /**
     * Вызывает обработчик и подтверждает сообщение только после успеха.
     *
     * @param array<string, array<string, string>> $messages
     * @param callable(EventEnvelope, string): void $handler
     */
    private function deliver(string $key, string $group, array $messages, callable $handler): ConsumeResult
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
                // Неизвестный контракт подтверждается и не повторяется (ЧТЗ 8.4).
                $invalid++;

                if ($this->acknowledge($key, $group, $messageId)) {
                    $acknowledged++;
                }

                continue;
            }

            try {
                $handler($envelope, $messageId);
            } catch (Throwable $exception) {
                $failed++;

                Log::error('Обработчик не принял событие; сообщение оставлено в pending.', [
                    'code' => self::CONSUMER_FAILED_CODE,
                    'message_id' => $messageId,
                    'event_type' => $envelope->eventType->value,
                    'exception' => $exception::class,
                ]);

                continue;
            }

            if (! $this->acknowledge($key, $group, $messageId)) {
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

    private function acknowledge(string $key, string $group, string $messageId): bool
    {
        try {
            $this->execute(['XACK', $key, $group, $messageId]);

            return true;
        } catch (Throwable $exception) {
            $this->logUnavailable('XACK', $exception);

            Log::error('Сообщение не подтверждено.', [
                'code' => self::ACK_FAILED_CODE,
                'message_id' => $messageId,
                'exception' => $exception::class,
            ]);

            return false;
        }
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
                // Незаполненный идентификатор исторического события остаётся null.
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
     * Поля XADD: конверт без добавления и переименования полей (FR-NTF-209).
     *
     * @return list<string>
     */
    private function fields(EventEnvelope $event): array
    {
        $fields = [];

        foreach ($event->toArray() as $name => $value) {
            // Незаполненное поле (correlation_id исторической строки Outbox)
            // не передаётся: пустая строка исказила бы контракт (FR-NTF-209).
            if ($value === null) {
                continue;
            }

            $fields[] = $name;
            $fields[] = is_array($value)
                ? (string) json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
                : (string) $value;
        }

        return $fields;
    }

    /**
     * Выполняет команду Redis и возвращает сырой ответ.
     *
     * executeRaw не применяет глобальный префикс подключения: имена потоков
     * полностью задаются ключами конфигурации.
     *
     * @param list<string> $arguments
     */
    private function execute(array $arguments): mixed
    {
        return $this->redis->command('executeRaw', [$arguments]);
    }

    private function key(string $stream): string
    {
        return $this->prefix.$stream;
    }

    private function logUnavailable(string $command, Throwable $exception): void
    {
        Log::error('Redis недоступен, проход потребления пропущен.', [
            'code' => self::TRANSPORT_UNAVAILABLE_CODE,
            'command' => $command,
            'exception' => $exception::class,
        ]);
    }
}
