<?php

declare(strict_types=1);

namespace App\Http\Problem;

use RuntimeException;

/**
 * Ожидаемая ошибка API с машиночитаемым кодом (ЧТЗ 8.5).
 *
 * Сообщение исключения равно коду причины и не содержит внутренних деталей,
 * поэтому даже при случайном логировании персональные данные не утекают.
 */
final class ApiProblem extends RuntimeException
{
    public function __construct(public readonly ErrorCode $errorCode)
    {
        parent::__construct($errorCode->value);
    }

    public static function notFound(): self
    {
        return new self(ErrorCode::NotFound);
    }

    public static function alreadySent(): self
    {
        return new self(ErrorCode::NotificationAlreadySent);
    }

    public static function validation(): self
    {
        return new self(ErrorCode::ValidationFailed);
    }
}
