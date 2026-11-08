<?php

declare(strict_types=1);

namespace App\Logging;

use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Единственная точка записи журнала сервиса (FR-OPS-202, FR-OPS-208).
 *
 * Каждая запись содержит действующий технический контекст (`correlation_id`,
 * `notification_id`, …) и явные поля вызова. Список полей ограничен
 * `TechnicalLogFormatter::ALLOWED_FIELDS`, поэтому адреса, телефоны, токены
 * и тела сообщений в журнал не попадают даже при ошибке в вызывающем коде.
 *
 * Класс не заменяет фасад `Log` полностью: он отвечает только за записи
 * сервиса, связанные с уведомлениями.
 */
final class TechnicalLogger
{
    public function __construct(private readonly TechnicalLogContext $context) {}

    /**
     * @param array<string, mixed> $fields явные технические поля записи
     */
    public function info(string $message, array $fields = []): void
    {
        $this->write('info', $message, $fields);
    }

    /**
     * @param array<string, mixed> $fields явные технические поля записи
     */
    public function warning(string $message, array $fields = []): void
    {
        $this->write('warning', $message, $fields);
    }

    /**
     * @param array<string, mixed> $fields явные технические поля записи
     */
    public function error(string $message, array $fields = []): void
    {
        $this->write('error', $message, $fields);
    }

    /**
     * Выполняет обратный вызов в области технического контекста записи.
     *
     * @param array<string, mixed> $fields
     * @param Closure(): mixed $callback
     */
    public function run(array $fields, Closure $callback): mixed
    {
        return $this->context->run($fields, $callback);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function write(string $level, string $message, array $fields): void
    {
        // Явные поля вызова важнее контекста области: значение записи точнее.
        $context = array_merge($this->context->fields(), $fields);

        Log::log($level, $message, TechnicalLogFormatter::filter($context));
    }
}
