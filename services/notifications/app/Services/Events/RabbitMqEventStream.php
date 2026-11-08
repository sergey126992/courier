<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Logging\TechnicalLogger;
use App\Services\Events\Amqp\AmqpBroker;
use App\Services\Events\Amqp\BrokerMessage;
use App\Services\Events\Contracts\EventStream;
use InvalidArgumentException;

/**
 * Транспорт событий сервиса на RabbitMQ (FR-NTF-541…552).
 *
 * Реализует тот же контракт `EventStream`, что и Redis Streams: потребитель
 * не знает, какой транспорт выбран конфигурацией. Подтверждение выполняется
 * только после фиксации эффекта — `acknowledge()` вызывает consumer после
 * COMMIT (FR-NTF-543). Неподтверждённое сообщение не теряется: перед выдачей
 * следующей пачки адаптер повторяет его публикацию со счётчиком попыток,
 * а после предела попыток отправляет в DLX через `reject(requeue: false)`.
 *
 * Prefetch, TTL и предел попыток приходят из конфигурации; имена exchange,
 * очередей и DLQ живут в `RabbitMqTopology`. Недоступность брокера не роняет
 * процесс: методы возвращают `null`/`false`, потребитель сообщает `degraded`,
 * а публикатор Outbox не ставит `published_at` и повторяет по backoff
 * (FR-NTF-548). Секреты подключения в журнал не попадают (FR-NTF-549).
 */
final class RabbitMqEventStream implements DeadLetterRedriver, EventStream
{
    /**
     * Задержка повторной выдачи отсчитывается от момента получения сообщения.
     *
     * @var array<string, array{fields: array<string, string>, headers: array<string, mixed>,
     *                          routing_key: string, received_at: float, queue: string}>
     */
    private array $pending = [];

    /**
     * Объявленные очереди: повторная декларация в одном процессе не нужна.
     *
     * @var array<string, bool>
     */
    private array $declared = [];

    public function __construct(
        private readonly AmqpBroker $broker,
        private readonly RabbitMqTopology $topology,
        private readonly TechnicalLogger $logger,
        private readonly int $pendingIdleSeconds = 60,
    ) {
        if ($pendingIdleSeconds < 0) {
            throw new InvalidArgumentException('Задержка повторной выдачи не может быть отрицательной.');
        }
    }

    public function ensureGroup(string $stream, string $group): bool
    {
        return $this->declare($this->topology->queueName($stream, $group));
    }

    public function read(string $stream, string $group, string $consumer, int $limit): ?array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Размер пачки потребления должен быть положительным.');
        }

        $queue = $this->topology->queueName($stream, $group);

        if (! $this->declare($queue)) {
            return null;
        }

        // Неподтверждённые сообщения предыдущей пачки повторяются здесь:
        // потребитель не сообщает об ошибке отдельно, отсутствие ack —
        // единственный признак того, что эффект не зафиксирован.
        $this->settle($queue);

        $messages = $this->broker->read($queue, $limit);

        if ($messages === null) {
            return null;
        }

        $result = [];

        foreach ($messages as $message) {
            $this->remember($queue, $message);
            $result[] = new StreamMessage($message->id, $message->fields);
        }

        return $result;
    }

    public function reclaim(string $stream, string $group, string $consumer, int $idleSeconds, int $limit): ?array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('Размер пачки потребления должен быть положительным.');
        }

        $queue = $this->topology->queueName($stream, $group);

        if (! $this->broker->isConnected()) {
            return null;
        }

        $now = microtime(true);
        $result = [];

        foreach ($this->pending as $id => $item) {
            if ($item['queue'] !== $queue) {
                continue;
            }

            if ($now - $item['received_at'] < max(0, $idleSeconds)) {
                continue;
            }

            // Момент получения обновляется: следующая выдача — после нового порога.
            $this->pending[$id]['received_at'] = $now;
            $result[] = new StreamMessage((string) $id, $item['fields']);

            if (count($result) >= $limit) {
                break;
            }
        }

        return $result;
    }

    public function acknowledge(string $stream, string $group, string $messageId): bool
    {
        if (! $this->broker->ack($messageId)) {
            return false;
        }

        unset($this->pending[$messageId]);

        return true;
    }

    public function publish(string $stream, array $fields): bool
    {
        $routingKey = $this->topology->routingKey($fields);

        if ($routingKey === null) {
            // Тип события без домена — ошибка конфигурации маршрутизации;
            // строка Outbox остаётся неопубликованной.
            $this->logger->error('Тип события не имеет домена маршрутизации в брокере.', [
                'error_code' => 'EVENT_ROUTING_UNKNOWN',
                'event_type' => (string) ($fields['event_type'] ?? ''),
            ]);

            return false;
        }

        // Публикация не требует объявления очередей: достаточно exchange.
        if (! $this->broker->ensureExchange($this->topology->exchange())) {
            return false;
        }

        return $this->broker->publish(
            $this->topology->exchange(),
            $routingKey,
            $fields,
            $this->topology->messageTtlMilliseconds(),
            ['x-attempt' => 0],
        );
    }

    public function deadLetterQueue(): string
    {
        return $this->topology->deadLetterQueue();
    }

    public function redriveDeadLetters(int $limit): ?int
    {
        if ($limit < 1) {
            return 0;
        }

        $queue = $this->topology->deadLetterQueue();

        if (! $this->declare($queue)) {
            return null;
        }

        $messages = $this->broker->read($queue, $limit);

        if ($messages === null) {
            return null;
        }

        $redriven = 0;

        foreach ($messages as $message) {
            $count = (int) ($message->headers['x-redriven'] ?? 0);

            if ($count >= $this->topology->maxAttempts()) {
                // Предел возвратов исчерпан: сообщение остаётся в DLQ
                // для осознанного разбора, но не участвует в цикле.
                $this->broker->reject($message->id, true);

                break;
            }

            $routingKey = $this->topology->routingKey($message->fields) ?? $message->routingKey;
            $published = $this->broker->publish(
                $this->topology->exchange(),
                $routingKey,
                $message->fields,
                $this->topology->messageTtlMilliseconds(),
                ['x-attempt' => 0, 'x-redriven' => $count + 1],
            );

            if (! $published) {
                // Брокер не принял сообщение: возвращаем его в DLQ без потери.
                $this->broker->reject($message->id, true);

                return $redriven;
            }

            if ($this->broker->ack($message->id)) {
                $redriven++;
            }
        }

        return $redriven;
    }

    /**
     * Повторяет неподтверждённые сообщения очереди или отправляет их в DLX.
     */
    private function settle(string $queue): void
    {
        foreach ($this->pending as $id => $item) {
            if ($item['queue'] !== $queue) {
                continue;
            }

            $attempts = (int) ($item['headers']['x-attempt'] ?? 0) + 1;

            if ($attempts >= $this->topology->maxAttempts()) {
                // Предел попыток: сообщение маршрутизируется в DLX (FR-NTF-543).
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
     * Объявляет топологию очереди один раз за процесс.
     */
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

    private function remember(string $queue, BrokerMessage $message): void
    {
        $this->pending[$message->id] = [
            'fields' => $message->fields,
            'headers' => $message->headers,
            'routing_key' => $message->routingKey,
            'received_at' => microtime(true),
            'queue' => $queue,
        ];
    }
}
