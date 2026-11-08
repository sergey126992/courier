<?php

declare(strict_types=1);

namespace App\Logging;

use Closure;

/**
 * Действующий технический контекст журнала сервиса (FR-OPS-201, FR-OPS-202).
 *
 * Сквозной `correlation_id` приходит из HTTP-запроса или из события потока
 * и действует, пока идёт обработка записи: потребитель, отправка поллером
 * и публикатор Outbox открывают область через `run()` и восстанавливают
 * предыдущее значение после её завершения, поэтому контекст одной записи
 * не протекает в следующую.
 *
 * В контекст попадают только технические поля: `TechnicalLogger` и
 * форматтер отбрасывают всё остальное.
 */
final class TechnicalLogContext
{
    /**
     * @var array<string, string|int|float|bool>
     */
    private array $fields = [];

    /**
     * Устанавливает контекст целиком: используется при входе в запрос.
     *
     * @param array<string, mixed> $fields
     */
    public function replace(array $fields): void
    {
        $this->fields = TechnicalLogFormatter::filter($fields);
    }

    /**
     * Дополняет действующий контекст: переданные значения важнее прежних.
     *
     * @param array<string, mixed> $fields
     */
    public function merge(array $fields): void
    {
        $this->fields = array_merge($this->fields, TechnicalLogFormatter::filter($fields));
    }

    /**
     * @return array<string, string|int|float|bool>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Сбрасывает контекст: процесс начинает запись без чужих полей.
     */
    public function reset(): void
    {
        $this->fields = [];
    }

    /**
     * Выполняет обратный вызов в области контекста и восстанавливает прежнее значение.
     *
     * @param array<string, mixed> $fields
     * @param Closure(): mixed $callback
     */
    public function run(array $fields, Closure $callback): mixed
    {
        $previous = $this->fields;

        $this->merge($fields);

        try {
            return $callback();
        } finally {
            $this->fields = $previous;
        }
    }
}
