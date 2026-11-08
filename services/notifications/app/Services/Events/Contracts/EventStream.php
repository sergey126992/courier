<?php

declare(strict_types=1);

namespace App\Services\Events\Contracts;

use App\Services\Events\StreamMessage;

/**
 * Порт потока событий сервиса (FR-NTF-201, FR-NTF-204).
 *
 * Потребитель зависит только от этого контракта: адаптер Redis Streams
 * заменяем, а тесты работают без сети (ЧТЗ 9.7). Подтверждение сообщения
 * выполняется отдельным вызовом и только после фиксации эффекта (FR-NTF-265).
 */
interface EventStream
{
    /**
     * Создаёт consumer group идемпотентно; false — транспорт недоступен.
     */
    public function ensureGroup(string $stream, string $group): bool;

    /**
     * Читает новые сообщения группы; null — транспорт недоступен.
     *
     * @return list<StreamMessage>|null
     */
    public function read(string $stream, string $group, string $consumer, int $limit): ?array;

    /**
     * Забирает сообщения, ожидающие дольше $idleSeconds; null — транспорт недоступен.
     *
     * @return list<StreamMessage>|null
     */
    public function reclaim(string $stream, string $group, string $consumer, int $idleSeconds, int $limit): ?array;

    /**
     * Подтверждает обработку одного сообщения (ack).
     */
    public function acknowledge(string $stream, string $group, string $messageId): bool;

    /**
     * Публикует сообщение в поток (FR-NTF-203, FR-NTF-283).
     *
     * Успешный `true` означает, что транспорт принял запись: только после
     * него публикатор Outbox ставит `published_at`. `false` — транспорт
     * недоступен, строка остаётся в Outbox и повторяется по backoff.
     *
     * @param array<string, string> $fields поля конверта (имя → значение)
     */
    public function publish(string $stream, array $fields): bool;
}
