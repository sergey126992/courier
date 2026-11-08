<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Подставной менеджер подключений Redis для контейнера сервиса (ЧТЗ 9.7).
 */
final class FakeRedisManager
{
    public function __construct(private readonly FakeRedisConnection $connection) {}

    public function connection(?string $name = null): FakeRedisConnection
    {
        return $this->connection;
    }
}
