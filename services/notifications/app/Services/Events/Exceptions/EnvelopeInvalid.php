<?php

declare(strict_types=1);

namespace App\Services\Events\Exceptions;

use RuntimeException;

/**
 * Сообщение потока не принято по контракту (FR-NTF-224, FR-NTF-225).
 *
 * Несёт код причины для `inbox_messages.error_code`. Сообщение с таким
 * отказом подтверждается без эффекта и не блокирует поток: строки payload
 * и значения полей в сообщение исключения не попадают.
 */
final class EnvelopeInvalid extends RuntimeException
{
    public function __construct(private readonly string $errorCode, string $message = '')
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
