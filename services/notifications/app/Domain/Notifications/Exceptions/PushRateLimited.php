<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * Провайдер push ограничил частоту запросов (FR-NTF-523).
 *
 * Инфраструктурная ошибка: повтор по backoff с учётом `Retry-After`.
 * Значение паузы сохраняется, чтобы отправитель назначил `next_attempt_at`
 * не раньше указанного провайдером срока.
 */
final class PushRateLimited extends NotificationInfrastructureException
{
    public function __construct(private readonly int $retryAfterSeconds)
    {
        parent::__construct(
            NotificationErrorCode::PUSH_PROVIDER_RATE_LIMITED,
            sprintf('Провайдер push ограничил частоту: повтор не раньше %d секунд.', $retryAfterSeconds),
        );
    }

    /**
     * Пауза, назначенная провайдером через `Retry-After`.
     */
    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
