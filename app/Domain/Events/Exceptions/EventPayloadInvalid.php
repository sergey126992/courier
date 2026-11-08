<?php

declare(strict_types=1);

namespace App\Domain\Events\Exceptions;

use App\Domain\Events\EventType;
use DomainException;

/**
 * В payload события нет обязательного поля (FR-NTF-113, ЧТЗ 8.6).
 *
 * Программная ошибка: публикатор не повторяет передачу, а разбирает причину.
 */
final class EventPayloadInvalid extends DomainException
{
    /**
     * Машиночитаемый код причины (ЧТЗ 8.6).
     */
    public const CODE = 'EVENT_PAYLOAD_INVALID';

    /**
     * @param list<string> $missingFields
     */
    private function __construct(
        private readonly string $eventType,
        private readonly array $missingFields,
    ) {
        parent::__construct(sprintf(
            'В payload события «%s» нет обязательных полей: %s.',
            $eventType,
            implode(', ', $missingFields),
        ));
    }

    /**
     * @param list<string> $missingFields
     */
    public static function forMissingFields(EventType $eventType, array $missingFields): self
    {
        return new self($eventType->value, $missingFields);
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    /**
     * @return list<string>
     */
    public function missingFields(): array
    {
        return $this->missingFields;
    }
}
