<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;
use Throwable;

/**
 * Провайдер push недоступен: сеть, таймаут, `5xx` или не получен токен
 * доступа (FR-NTF-523).
 *
 * Инфраструктурная ошибка: строка журнала получает `error` и повтор по
 * backoff; при исчерпании попыток — запись DLQ. Код ответа провайдера, если он
 * известен, попадает в журнал попыток. Секретов и токенов в тексте нет.
 */
final class PushProviderUnavailable extends NotificationInfrastructureException
{
    public static function status(int $status): self
    {
        return new self(
            NotificationErrorCode::PUSH_PROVIDER_UNAVAILABLE,
            sprintf('Провайдер push недоступен: HTTP %d.', $status),
            null,
            (string) $status,
        );
    }

    public static function timeout(?Throwable $previous = null): self
    {
        return new self(
            NotificationErrorCode::PUSH_PROVIDER_UNAVAILABLE,
            'Провайдер push не ответил в отведённое время.',
            $previous,
        );
    }

    public static function credentials(?Throwable $previous = null): self
    {
        return new self(
            NotificationErrorCode::PUSH_PROVIDER_UNAVAILABLE,
            'Токен доступа FCM не получен: проверьте креды окружения.',
            $previous,
        );
    }
}
