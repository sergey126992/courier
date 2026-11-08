<?php

declare(strict_types=1);

namespace App\Services\Events\Amqp;

/**
 * Сообщение брокера в форме, не зависящей от клиентской библиотеки.
 *
 * Поля конверта читаются как карта «имя → значение»: адаптер не добавляет
 * и не переименовывает поля контракта (FR-NTF-209). `headers` содержит
 * служебные заголовки повтора, `routingKey` — ключ маршрутизации, с которым
 * сообщение пришло (нужен для возврата сообщения DLQ в основной поток).
 */
final readonly class BrokerMessage
{
    /**
     * @param array<string, string> $fields поля конверта события
     * @param array<string, mixed> $headers служебные заголовки повтора
     */
    public function __construct(
        public string $id,
        public array $fields,
        public array $headers = [],
        public string $routingKey = '',
    ) {}
}
