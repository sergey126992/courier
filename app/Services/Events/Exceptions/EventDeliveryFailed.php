<?php

declare(strict_types=1);

namespace App\Services\Events\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Событие не принято хотя бы одним слушателем (FR-NTF-126, ЧТЗ 8.6).
 *
 * Инфраструктурная ошибка: публикатор фиксирует её в строке Outbox и
 * повторяет передачу с backoff (FR-NTF-107).
 */
final class EventDeliveryFailed extends RuntimeException
{
    /**
     * Машиночитаемый код причины (ЧТЗ 8.6).
     */
    public const CODE = 'OUTBOX_PUBLISH_FAILED';

    /**
     * @param list<Throwable> $failures
     */
    private function __construct(
        private readonly string $messageId,
        private readonly array $failures,
    ) {
        parent::__construct(
            sprintf('Событие %s не принято слушателями: %d.', $messageId, count($failures)),
            0,
            $failures[0] ?? null,
        );
    }

    /**
     * @param list<Throwable> $failures
     */
    public static function make(string $messageId, array $failures): self
    {
        return new self($messageId, $failures);
    }

    public function errorCode(): string
    {
        return self::CODE;
    }

    public function messageId(): string
    {
        return $this->messageId;
    }

    /**
     * @return list<Throwable>
     */
    public function failures(): array
    {
        return $this->failures;
    }
}
