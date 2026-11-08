<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Events\Amqp\AmqpBroker;
use App\Services\Events\Amqp\BrokerMessage;

/**
 * Подставной брокер для проверок без сети (ЧТЗ 9.7).
 *
 * Повторяет нужную часть топологии AMQP: topic-привязки, маршрутизацию
 * публикаций по очередям, подтверждение и отклонение с уходом в DLX.
 * Глубины очередей и доступность брокера управляются из теста.
 */
final class FakeAmqpBroker implements AmqpBroker
{
    /**
     * Опубликованные сообщения: exchange → ключ маршрутизации → поля.
     *
     * @var list<array{exchange: string, routing_key: string, fields: array<string, string>, headers: array<string, mixed>}>
     */
    public array $published = [];

    /**
     * Сообщения очередей, которые отдаст basic_get.
     *
     * @var array<string, list<BrokerMessage>>
     */
    public array $queues = [];

    /**
     * Привязки обменников к очередям.
     *
     * @var list<array{exchange: string, queue: string, pattern: string}>
     */
    public array $bindings = [];

    /**
     * Аргументы очередей (DLX, TTL).
     *
     * @var array<string, array<string, mixed>>
     */
    public array $queueArguments = [];

    /**
     * Глубины очередей для команды проверки состояния.
     *
     * @var array<string, int>
     */
    public array $depths = [];

    /**
     * Сообщения, отклонённые с отправкой в DLX.
     *
     * @var list<string>
     */
    public array $rejected = [];

    /**
     * Сообщения, подтверждённые обработчиком.
     *
     * @var list<string>
     */
    public array $acknowledged = [];

    /**
     * Выданные без подтверждения сообщения: идентификатор → очередь и сообщение.
     *
     * @var array<string, array{queue: string, message: BrokerMessage}>
     */
    private array $delivered = [];

    private int $nextId = 1;

    /**
     * Имитация удалённой очереди: публикация без привязки считается неуспешной.
     */
    public bool $failUnroutable = false;

    public bool $available = true;

    public function ensureExchange(string $exchange, string $type = 'topic'): bool
    {
        return $this->available;
    }

    public function ensureQueue(string $queue, array $arguments = []): bool
    {
        if (! $this->available) {
            return false;
        }

        $this->queueArguments[$queue] = $arguments;

        return true;
    }

    public function bind(string $exchange, string $queue, string $pattern): bool
    {
        if (! $this->available) {
            return false;
        }

        $this->bindings[] = ['exchange' => $exchange, 'queue' => $queue, 'pattern' => $pattern];

        return true;
    }

    public function publish(string $exchange, string $routingKey, array $fields, int $expirationMs, array $headers): bool
    {
        if (! $this->available) {
            return false;
        }

        $this->published[] = [
            'exchange' => $exchange,
            'routing_key' => $routingKey,
            'fields' => $fields,
            'headers' => $headers,
        ];

        $routed = $this->route($exchange, $routingKey, new BrokerMessage(
            id: (string) $this->nextId++,
            fields: $fields,
            headers: $headers,
            routingKey: $routingKey,
        ));

        return ! $this->failUnroutable || $routed > 0;
    }

    public function read(string $queue, int $limit): ?array
    {
        if (! $this->available) {
            return null;
        }

        $messages = $this->queues[$queue] ?? [];
        $batch = array_splice($messages, 0, $limit);
        $this->queues[$queue] = $messages;
        $result = [];

        foreach ($batch as $message) {
            $id = 'delivery-'.$message->id;
            $this->delivered[$id] = ['queue' => $queue, 'message' => $message];
            $result[] = new BrokerMessage($id, $message->fields, $message->headers, $message->routingKey);
        }

        return $result;
    }

    public function ack(string $messageId): bool
    {
        if (! $this->available) {
            return false;
        }

        $this->acknowledged[] = $messageId;
        unset($this->delivered[$messageId]);

        return true;
    }

    public function reject(string $messageId, bool $requeue): bool
    {
        if (! $this->available) {
            return false;
        }

        $delivery = $this->delivered[$messageId] ?? null;

        if ($delivery === null || $requeue) {
            return true;
        }

        unset($this->delivered[$messageId]);
        $this->rejected[] = $messageId;

        $arguments = $this->queueArguments[$delivery['queue']] ?? [];
        $exchange = $arguments['x-dead-letter-exchange'] ?? null;

        if (! is_string($exchange) || $exchange === '') {
            return true;
        }

        $key = $arguments['x-dead-letter-routing-key'] ?? $delivery['message']->routingKey;
        $this->route($exchange, is_string($key) ? $key : '', $delivery['message']);

        return true;
    }

    public function depth(string $queue): ?int
    {
        if (! $this->available) {
            return null;
        }

        return $this->depths[$queue] ?? count($this->queues[$queue] ?? []);
    }

    public function isConnected(): bool
    {
        return $this->available;
    }

    public function close(): void {}

    /**
     * Маршрутизирует сообщение по topic-привязкам обменника.
     */
    private function route(string $exchange, string $routingKey, BrokerMessage $message): int
    {
        $routed = 0;

        foreach ($this->bindings as $binding) {
            if ($binding['exchange'] !== $exchange || ! $this->matches($binding['pattern'], $routingKey)) {
                continue;
            }

            $this->queues[$binding['queue']][] = $message;
            $routed++;
        }

        return $routed;
    }

    /**
     * Сопоставление ключа с шаблоном темы: `*` — одно слово, `#` — остальные.
     */
    private function matches(string $pattern, string $routingKey): bool
    {
        if ($pattern === '#') {
            return true;
        }

        $patternParts = explode('.', $pattern);
        $keyParts = explode('.', $routingKey);
        $index = 0;

        foreach ($patternParts as $part) {
            if ($part === '#') {
                return true;
            }

            if (! isset($keyParts[$index])) {
                return false;
            }

            if ($part !== '*' && $part !== $keyParts[$index]) {
                return false;
            }

            $index++;
        }

        return $index === count($keyParts);
    }
}
