<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Services\Events\Amqp\AmqpBroker;

/**
 * Состояние брокера для `/health` и команды `notifications:broker-health`
 * (FR-NTF-548, FR-NTF-552).
 *
 * При транспорте `redis` проверка выключена и в ответе `/health` не
 * появляется: форма прежних полей не меняется. При `rabbitmq` читаются
 * соединение и глубина очередей потребителя и DLQ. Персональных данных,
 * адресов и секретов в снимке нет (FR-NTF-549).
 */
final class BrokerHealth
{
    /**
     * Проверка не применима: транспорт не брокер.
     */
    public const STATUS_DISABLED = 'disabled';

    /**
     * Проверка в норме.
     */
    public const STATUS_OK = 'ok';

    /**
     * Проверка при сбое.
     */
    public const STATUS_ERROR = 'error';

    /**
     * @param list<string> $queues очереди потребителей для проверки
     */
    private function __construct(
        private readonly ?AmqpBroker $broker,
        private readonly ?RabbitMqTopology $topology,
        private readonly string $driver,
        private readonly array $queues,
    ) {}

    /**
     * Проверка для транспорта, у которого нет брокера.
     */
    public static function disabled(string $driver): self
    {
        return new self(null, null, $driver, []);
    }

    /**
     * Проверка брокера RabbitMQ.
     *
     * @param list<string> $queues
     */
    public static function forBroker(AmqpBroker $broker, RabbitMqTopology $topology, string $driver, array $queues): self
    {
        return new self($broker, $topology, $driver, $queues);
    }

    public function enabled(): bool
    {
        return $this->broker !== null && $this->topology !== null;
    }

    public function status(): string
    {
        if (! $this->enabled()) {
            return self::STATUS_DISABLED;
        }

        $snapshot = $this->snapshot();

        if ($snapshot['connected'] !== true) {
            return self::STATUS_ERROR;
        }

        foreach ($snapshot['queues'] as $depth) {
            if ($depth === null) {
                return self::STATUS_ERROR;
            }
        }

        return $snapshot['dlq']['depth'] === null ? self::STATUS_ERROR : self::STATUS_OK;
    }

    /**
     * Снимок состояния брокера без персональных данных.
     *
     * @return array{driver: string, enabled: bool, connected: bool,
     *               queues: array<string, int|null>, dlq: array{name: string|null, depth: int|null}}
     */
    public function snapshot(): array
    {
        if (! $this->enabled()) {
            return [
                'driver' => $this->driver,
                'enabled' => false,
                'connected' => false,
                'queues' => [],
                'dlq' => ['name' => null, 'depth' => null],
            ];
        }

        /** @var AmqpBroker $broker */
        $broker = $this->broker;
        /** @var RabbitMqTopology $topology */
        $topology = $this->topology;

        $queues = [];

        foreach ($this->queues as $queue) {
            // Топология объявляется до чтения глубины: на чистом брокере
            // очередей ещё нет, и пассивная проверка вернула бы ошибку.
            $topology->ensure($broker, $queue);
            $queues[$queue] = $broker->depth($queue);
        }

        $topology->ensure($broker, $topology->deadLetterQueue());

        return [
            'driver' => $this->driver,
            'enabled' => true,
            'connected' => $broker->isConnected(),
            'queues' => $queues,
            'dlq' => [
                'name' => $topology->deadLetterQueue(),
                'depth' => $broker->depth($topology->deadLetterQueue()),
            ],
        ];
    }
}
