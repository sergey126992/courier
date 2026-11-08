<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Redis\Connections\Connection;
use Throwable;

/**
 * Подставное подключение Redis для проверки /health без сети (ЧТЗ 9.7).
 *
 * Возвращает заранее заданные ответы команд и умеет имитировать отказ:
 * реальный Redis в тестах не нужен, а тип подключения совпадает с рабочим.
 */
final class FakeRedisConnection extends Connection
{
    /**
     * @param array<string, mixed> $responses ответы по имени команды в нижнем регистре;
     *                                        исключение в значении имитирует отказ команды
     * @param Throwable|null $failure ошибка подключения вместо ответа
     */
    public function __construct(
        private readonly array $responses = [],
        private readonly ?Throwable $failure = null,
    ) {}

    /**
     * @param array<int, mixed>|string $channels
     * @param string $method
     */
    public function createSubscription($channels, Closure $callback, $method = 'subscribe'): void
    {
        // Подписки в проверке состояния не используются.
    }

    /**
     * @param string $method
     * @param array<int, mixed> $parameters
     * @return mixed
     */
    public function command($method, array $parameters = [])
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        $response = $this->responses[strtolower((string) $method)] ?? null;

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}
