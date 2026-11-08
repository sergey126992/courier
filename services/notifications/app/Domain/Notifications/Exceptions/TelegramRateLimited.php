<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * Telegram ограничил частоту запросов (FR-NTF-436).
 *
 * Инфраструктурная ошибка: строка журнала получает `error` и повтор
 * по backoff. Значение `retry_after` сохраняется в исключении, чтобы
 * поллер и отправитель могли назначить повтор не раньше этого срока,
 * не меняя схему журнала.
 */
final class TelegramRateLimited extends NotificationInfrastructureException
{
    public function __construct(private readonly int $retryAfterSeconds)
    {
        parent::__construct(
            NotificationErrorCode::TELEGRAM_RATE_LIMITED,
            sprintf('Telegram ограничил частоту запросов: повтор не раньше %d секунд.', $retryAfterSeconds),
        );
    }

    /**
     * Пауза, назначенная Telegram; при отсутствии значения — из конфигурации.
     */
    public function retryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
