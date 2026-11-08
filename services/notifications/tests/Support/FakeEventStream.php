<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Events\Contracts\EventStream;
use App\Services\Events\StreamMessage;

/**
 * Подставной поток событий (ЧТЗ 9.7).
 *
 * Отдаёт заранее подготовленные сообщения, помнит подтверждения и умеет
 * имитировать недоступный транспорт. Реальный Redis в тестах не нужен.
 */
final class FakeEventStream implements EventStream
{
    /**
     * Подтверждённые идентификаторы сообщений.
     *
     * @var list<string>
     */
    public array $acknowledged = [];

    /**
     * Пороги «зависших» сообщений, с которыми вызывали XAUTOCLAIM.
     *
     * @var list<int>
     */
    public array $reclaimIdleSeconds = [];

    /**
     * Размеры пачек чтения.
     *
     * @var list<int>
     */
    public array $readLimits = [];

    /**
     * Опубликованные сообщения потоков: имя потока → поля конверта.
     *
     * @var list<array{stream: string, fields: array<string, string>}>
     */
    public array $published = [];

    /**
     * Поток доступен; false — имитация недоступного Redis.
     */
    public bool $available = true;

    /**
     * @param list<StreamMessage> $messages новые сообщения
     * @param list<StreamMessage> $pending сообщения, ожидающие повторной выдачи
     */
    public function __construct(
        private array $messages = [],
        private array $pending = [],
    ) {}

    public function ensureGroup(string $stream, string $group): bool
    {
        return $this->available;
    }

    public function read(string $stream, string $group, string $consumer, int $limit): ?array
    {
        if (! $this->available) {
            return null;
        }

        $this->readLimits[] = $limit;

        return array_splice($this->messages, 0, $limit);
    }

    public function reclaim(string $stream, string $group, string $consumer, int $idleSeconds, int $limit): ?array
    {
        if (! $this->available) {
            return null;
        }

        $this->reclaimIdleSeconds[] = $idleSeconds;

        $claimed = array_splice($this->pending, 0, $limit);

        return $claimed;
    }

    public function acknowledge(string $stream, string $group, string $messageId): bool
    {
        if (! $this->available) {
            return false;
        }

        $this->acknowledged[] = $messageId;

        return true;
    }

    public function publish(string $stream, array $fields): bool
    {
        if (! $this->available) {
            return false;
        }

        $this->published[] = ['stream' => $stream, 'fields' => $fields];

        return true;
    }
}
