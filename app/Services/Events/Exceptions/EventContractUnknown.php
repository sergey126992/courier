<?php

declare(strict_types=1);

namespace App\Services\Events\Exceptions;

use RuntimeException;

/**
 * Для типа события нет файла схемы нужной версии (FR-NTF-224, ЧТЗ 8.4).
 *
 * Контрактная ошибка: событие не передаётся в транспорт, но и не теряется —
 * строка Outbox остаётся с кодом причины и доступна для разбора.
 */
final class EventContractUnknown extends RuntimeException
{
    /**
     * Машиночитаемый код причины (ЧТЗ 8.5).
     */
    public const CODE = 'EVENT_CONTRACT_UNKNOWN';

    private function __construct(
        private readonly string $eventType,
        private readonly string $schemaVersion,
    ) {
        parent::__construct(sprintf(
            'Схема контракта события «%s» версии %s не найдена.',
            $eventType,
            $schemaVersion,
        ));
    }

    public static function make(string $eventType, string $schemaVersion): self
    {
        return new self($eventType, $schemaVersion);
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
}
