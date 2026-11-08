<?php

declare(strict_types=1);

namespace App\Services\Notifications\Service;

/**
 * Доменный результат вызова служебного API сервиса оповещений (FR-OPS-403).
 *
 * Отказ сервиса не пробрасывается исключением: вызывающая страница получает
 * результат с кодом причины и показывает безопасный текст без внутреннего
 * сообщения. Недоступность сервиса — отдельное исключение
 * `NotificationServiceUnavailable`: экран тогда блокирует изменения.
 */
final readonly class NotificationServiceResult
{
    /**
     * @param array<string, mixed> $data данные ответа по allowlist
     */
    public function __construct(
        public bool $ok,
        public int $status,
        public ?string $errorCode,
        public array $data,
    ) {}

    /**
     * Успешный ответ сервиса.
     *
     * @param array<string, mixed> $data
     */
    public static function ok(array $data = [], int $status = 200): self
    {
        return new self(true, $status, null, $data);
    }

    /**
     * Отказ по запросу: код причины из `application/problem+json`.
     */
    public static function failed(int $status, ?string $errorCode = null): self
    {
        return new self(false, $status, $errorCode, []);
    }
}
