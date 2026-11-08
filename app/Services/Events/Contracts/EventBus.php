<?php

declare(strict_types=1);

namespace App\Services\Events\Contracts;

use App\Domain\Events\EventEnvelope;
use App\Services\Events\ConsumeResult;

/**
 * Порт шины событий (FR-NTF-201).
 *
 * Домен и действия зависят только от этого контракта: локальная доставка
 * в процессе и внешний посредник (Redis Streams) взаимозаменяемы, а выбор
 * реализации выполняется сервис-провайдером по конфигурации (FR-NTF-206).
 *
 * Доставка — «хотя бы один раз», порядок не гарантируется (FR-NTF-205):
 * потребитель обязан корректно обрабатывать повтор и любой порядок событий
 * одного агрегата.
 */
interface EventBus
{
    /**
     * Публикует событие в транспорт.
     *
     * Успешный возврат означает, что транспорт принял событие: только после
     * этого публикатор Outbox ставит отметку published_at (FR-NTF-203).
     * Исключение — инфраструктурная ошибка, повтор выполняет публикатор.
     */
    public function publish(EventEnvelope $event): void;

    /**
     * Читает пачку сообщений потока, вызывает обработчик и подтверждает
     * сообщение только после успешной фиксации эффекта (FR-NTF-204).
     *
     * Исключение обработчика оставляет сообщение в pending: повторная выдача
     * выполняется после pending_idle_seconds из конфигурации.
     *
     * @param callable(EventEnvelope, string): void $handler получает событие и идентификатор сообщения
     */
    public function consume(string $stream, string $group, string $consumer, int $limit, callable $handler): ConsumeResult;

    /**
     * Подтверждает обработку одного сообщения (ack).
     */
    public function ack(string $stream, string $group, string $messageId): void;

    /**
     * Возвращает в обработку сообщения, ожидающие дольше $idleSeconds.
     *
     * @param callable(EventEnvelope, string): void $handler получает событие и идентификатор сообщения
     */
    public function reclaim(string $stream, string $group, string $consumer, int $idleSeconds, int $limit, callable $handler): ConsumeResult;
}
