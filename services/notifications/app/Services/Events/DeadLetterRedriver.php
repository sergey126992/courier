<?php

declare(strict_types=1);

namespace App\Services\Events;

/**
 * Возврат сообщений DLQ брокера в основной поток (FR-NTF-510, FR-NTF-544).
 *
 * Отдельного разбора у DLX нет: сообщение возвращается в exchange с ключом
 * маршрутизации и обнулённым счётчиком попыток, после чего обрабатывается
 * обычными правилами (Inbox, дедупликация, `dlq_entries` при терминальном
 * отказе канала). Предел возвратов конечен, чтобы DLQ не стал бесконечным
 * циклом.
 */
interface DeadLetterRedriver
{
    /**
     * Имя очереди DLX/DLQ из конфигурации.
     */
    public function deadLetterQueue(): string;

    /**
     * Возвращает до $limit сообщений в основной поток.
     *
     * Возвращает число возвращённых сообщений; `null` — брокер недоступен.
     */
    public function redriveDeadLetters(int $limit): ?int;
}
