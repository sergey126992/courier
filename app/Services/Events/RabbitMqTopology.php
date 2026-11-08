<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Services\Events\Amqp\AmqpBroker;
use InvalidArgumentException;

/**
 * Топология брокера монолита из конфигурации (FR-NTF-541…552).
 *
 * Монолит только публикует события в exchange типа `topic`; очередь нужна
 * потребителю обратных событий и проверкам контракта. Имена exchange, DLX,
 * шаблоны маршрутизации и TTL приходят из `config/events.php`: литералов
 * в коде нет (FR-OPS-508, FR-NTF-549).
 */
final readonly class RabbitMqTopology
{
    /**
     * @param array{exchange: string, dlx_exchange: string, message_ttl_seconds: int,
     *              max_attempts: int} $config блок rabbitmq
     * @param array<string, string> $routing соответствие «префикс типа события → домен»
     * @param list<string> $streams имена потоков монолита
     */
    public function __construct(
        private array $config,
        private array $routing,
        private array $streams,
    ) {
        if (trim((string) ($this->config['exchange'] ?? '')) === ''
            || trim((string) ($this->config['dlx_exchange'] ?? '')) === '') {
            throw new InvalidArgumentException('Не заданы имена exchange и DLX монолита.');
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

    public function messageTtlMilliseconds(): int
    {
        return $this->config['message_ttl_seconds'] * 1000;
    }

    public function maxAttempts(): int
    {
        return $this->config['max_attempts'];
    }

    public function queueName(string $stream, string $group): string
    {
        return $stream.'.'.$group;
    }

    /**
     * Ключ маршрутизации по типу события.
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
     * Объявляет exchange, DLX, очередь и привязку по домену потока.
     */
    public function ensure(AmqpBroker $broker, string $queue): bool
    {
        if (! $broker->ensureExchange($this->exchange()) || ! $broker->ensureExchange($this->dlxExchange())) {
            return false;
        }

        $arguments = [
            'x-dead-letter-exchange' => $this->dlxExchange(),
            'x-message-ttl' => $this->messageTtlMilliseconds(),
        ];

        return $broker->ensureQueue($queue, $arguments)
            && $broker->bind($this->exchange(), $queue, $this->bindingPattern($queue));
    }

    public function bindingPattern(string $queue): string
    {
        return $this->domainOf($this->streamOf($queue)).'.'.'#';
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
