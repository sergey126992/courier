<?php

declare(strict_types=1);

namespace App\Services\Events\Amqp;

/**
 * Минимальный порт брокера AMQP (FR-NTF-541…552).
 *
 * Адаптер транспорта зависит от этого порта, а не от клиентской библиотеки:
 * поэтому проверки работают без сети, а смена клиента не задевает домен
 * и потребителей (ЧТЗ 9.7). Ошибки доступа не пробрасываются наружу:
 * методы возвращают `false` или `null`, вызывающий код сообщает `degraded`.
 */
interface AmqpBroker
{
    /**
     * Объявляет exchange идемпотентно (тип `topic`).
     */
    public function ensureExchange(string $exchange, string $type = 'topic'): bool;

    /**
     * Объявляет durable-очередь с аргументами (TTL, DLX).
     *
     * @param array<string, mixed> $arguments аргументы очереди
     */
    public function ensureQueue(string $queue, array $arguments = []): bool;

    /**
     * Связывает очередь с exchange по шаблону темы.
     */
    public function bind(string $exchange, string $queue, string $pattern): bool;

    /**
     * Публикует сообщение; `true` — брокер принял его.
     *
     * @param array<string, string> $fields поля конверта
     * @param array<string, mixed> $headers служебные заголовки
     */
    public function publish(string $exchange, string $routingKey, array $fields, int $expirationMs, array $headers): bool;

    /**
     * Забирает до $limit сообщений без подтверждения; `null` — брокер недоступен.
     *
     * @return list<BrokerMessage>|null
     */
    public function read(string $queue, int $limit): ?array;

    /**
     * Подтверждает обработку сообщения (basic_ack).
     */
    public function ack(string $messageId): bool;

    /**
     * Отклоняет сообщение: `$requeue=false` отправляет его в DLX.
     */
    public function reject(string $messageId, bool $requeue): bool;

    /**
     * Число готовых сообщений в очереди; `null` — брокер недоступен.
     */
    public function depth(string $queue): ?int;

    /**
     * Состояние соединения.
     */
    public function isConnected(): bool;

    /**
     * Закрывает соединение; повторное использование разрешено.
     */
    public function close(): void;
}
