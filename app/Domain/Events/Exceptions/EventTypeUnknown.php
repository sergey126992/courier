<?php

declare(strict_types=1);

namespace App\Domain\Events\Exceptions;

use DomainException;

/**
 * Тип события не зарегистрирован в реестре (FR-NTF-123, ЧТЗ 8.6).
 *
 * Бизнес-ошибка: событие помечается опубликованным, повтора нет.
 */
final class EventTypeUnknown extends DomainException
{
    /**
     * Машиночитаемый код причины (ЧТЗ 8.6).
     */
    public const CODE = 'EVENT_TYPE_UNKNOWN';

    private function __construct(
        private readonly string $eventType,
    ) {
        parent::__construct(sprintf('Тип события «%s» не зарегистрирован.', $eventType));
    }

    public static function make(string $eventType): self
    {
        return new self($eventType);
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    public function eventType(): string
    {
        return $this->eventType;
    }
}
