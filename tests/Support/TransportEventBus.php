<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Events\EventEnvelope;
use App\Services\Events\ConsumeResult;
use App\Services\Events\Contracts\EventBus;
use RuntimeException;

/**
 * Транспорт-заглушка публикатора: считает вызовы и умеет имитировать отказ XADD.
 */
final class TransportEventBus implements EventBus
{
    /**
     * События, принятые транспортом.
     *
     * @var list<EventEnvelope>
     */
    public array $published = [];

    /**
     * Число вызовов publish, включая неуспешные.
     */
    public int $calls = 0;

    /**
     * Признак недоступного транспорта: publish бросает исключение.
     */
    public bool $failing = false;

    public function publish(EventEnvelope $event): void
    {
        $this->calls++;

        if ($this->failing) {
            throw new RuntimeException('XADD недоступен.');
        }

        $this->published[] = $event;
    }

    public function consume(string $stream, string $group, string $consumer, int $limit, callable $handler): ConsumeResult
    {
        return ConsumeResult::empty();
    }

    public function ack(string $stream, string $group, string $messageId): void {}

    public function reclaim(string $stream, string $group, string $consumer, int $idleSeconds, int $limit, callable $handler): ConsumeResult
    {
        return ConsumeResult::empty();
    }
}
