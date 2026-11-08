<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Services\Events\Amqp\AmqpBroker;
use InvalidArgumentException;

/**
 * Топология брокера из конфигурации (FR-NTF-542…545).
 *
 * Exchange типа `topic`, отдельная очередь на потребителя (имя строится
 * из потока и группы), DLX и очередь DLQ, per-message и queue TTL.
 * Имён и шаблонов в коде нет: потоки, exchange, DLX и DLQ приходят
 * из `config/notifications.php` (FR-OPS-508, FR-NTF-549).
 */
final readonly class RabbitMqTopology
{
    /**
     * Шаблон привязки «вся очередь DLQ»: синтаксис AMQP, не имя.
     */
    private const DLQ_BINDING = '#';

    /**
     * @param array{exchange: string, dlx_exchange: string, dlq_queue: string,
     *              message_ttl_seconds: int, max_attempts: int} $config блок rabbitmq
     * @param list<string> $streams имена потоков сервиса
     * @param array<string, string> $routing соответствие «префикс типа события → домен»
     */
    public function __construct(
        private array $config,
        private array $streams,
        private array $routing,
    ) {
        foreach (['exchange', 'dlx_exchange', 'dlq_queue'] as $key) {
            if (trim((string) ($this->config[$key] ?? '')) === '') {
                throw new InvalidArgumentException(sprintf('Не задано имя топологии брокера: %s.', $key));
            }
        }

        if ($this->config['message_ttl_seconds'] < 1 || $this->config['max_attempts'] < 1) {
            throw new InvalidArgumentException('TTL и предел попыток брокера должны быть положительными.');
        }
    }

    public function exchange(): string
    {
        return $this->config['exchange'];
    }

    public function dlxExchange(): string
    {
        return $this->config['dlx_exchange'];
    }

    public function deadLetterQueue(): string
    {
        return $this->config['dlq_queue'];
    }

    public function messageTtlMilliseconds(): int
    {
        return $this->config['message_ttl_seconds'] * 1000;
    }

    public function maxAttempts(): int
    {
        return $this->config['max_attempts'];
    }

    /**
     * Имя очереди потребителя: поток и группа, без литералов (FR-NTF-542).
     */
    public function queueName(string $stream, string $group): string
    {
        return $stream.'.'.$group;
    }

    /**
     * Ключ маршрутизации по типу события: домен и полный тип.
     *
     * @param array<string, string> $fields поля конверта
     */
    public function routingKey(array $fields): ?string
    {
        $eventType = (string) ($fields['event_type'] ?? '');
        $prefix = str_contains($eventType, '.') ? strstr($eventType, '.', true) : $eventType;
        $domain = $this->routing[(string) $prefix] ?? null;

        if ($domain === null || $domain === '') {
            return null;
        }

        return $domain.'.'.$eventType;
    }

    /**
     * Объявляет exchange, DLX, очередь DLQ, очередь потребителя и привязки.
     */
    public function ensure(AmqpBroker $broker, string $queue): bool
    {
        if (! $broker->ensureExchange($this->exchange()) || ! $broker->ensureExchange($this->dlxExchange())) {
            return false;
        }

        if (! $broker->ensureQueue($this->deadLetterQueue())
            || ! $broker->bind($this->dlxExchange(), $this->deadLetterQueue(), self::DLQ_BINDING)) {
            return false;
        }

        if ($queue === $this->deadLetterQueue()) {
            return true;
        }

        $arguments = [
            // Истёкшее сообщение и исчерпавшее попытки уходит в DLX (FR-NTF-544).
            'x-dead-letter-exchange' => $this->dlxExchange(),
            'x-dead-letter-routing-key' => $queue,
            'x-message-ttl' => $this->messageTtlMilliseconds(),
        ];

        return $broker->ensureQueue($queue, $arguments)
            && $broker->bind($this->exchange(), $queue, $this->bindingPattern($queue));
    }

    /**
     * Шаблон привязки очереди потребителя по домену потока.
     */
    public function bindingPattern(string $queue): string
    {
        if ($queue === $this->deadLetterQueue()) {
            return self::DLQ_BINDING;
        }

        $stream = $this->streamOf($queue);

        return $this->domainOf($stream).'.'.'#';
    }

    /**
     * Поток очереди: имя очереди — поток и группа, разделённые точкой.
     */
    private function streamOf(string $queue): string
    {
        foreach ($this->streams as $stream) {
            if ($queue === $stream || str_starts_with($queue, $stream.'.')) {
                return $stream;
            }
        }

        return $queue;
    }

    /**
     * Домен потока — последний сегмент имени (`events.orders` → `orders`).
     */
    private function domainOf(string $stream): string
    {
        $position = strrpos($stream, '.');

        return $position === false ? $stream : substr($stream, $position + 1);
    }
}
