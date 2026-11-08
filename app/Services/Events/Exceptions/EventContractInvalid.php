<?php

declare(strict_types=1);

namespace App\Services\Events\Exceptions;

use RuntimeException;

/**
 * Payload события не соответствует JSON Schema контракта (FR-NTF-225, ЧТЗ 8.5).
 *
 * Контрактная ошибка: повтор передачи не поможет, строка Outbox остаётся
 * с кодом причины и видна для разбора. В сообщение попадают только тип
 * события, версия схемы, ключевое слово и путь поля — значения payload
 * (в событии контактов это персональные данные) не журналируются (FR-NTF-235).
 */
final class EventContractInvalid extends RuntimeException
{
    /**
     * Машиночитаемый код причины (ЧТЗ 8.5).
     */
    public const CODE = 'EVENT_CONTRACT_INVALID';

    private function __construct(
        private readonly string $eventType,
        private readonly string $schemaVersion,
        private readonly string $keyword,
        private readonly string $pointer,
    ) {
        parent::__construct(sprintf(
            'Payload события «%s» версии %s не соответствует схеме: %s в %s.',
            $eventType,
            $schemaVersion,
            $keyword,
            $pointer,
        ));
    }

    public static function make(string $eventType, string $schemaVersion, string $keyword, string $pointer): self
    {
        return new self($eventType, $schemaVersion, $keyword, $pointer);
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    public function schemaVersion(): string
    {
        return $this->schemaVersion;
    }

    /**
     * Ключевое слово JSON Schema, на котором проверка остановилась.
     */
    public function keyword(): string
    {
        return $this->keyword;
    }

    /**
     * Путь проблемного поля в payload (без значения).
     */
    public function pointer(): string
    {
        return $this->pointer;
    }
}
