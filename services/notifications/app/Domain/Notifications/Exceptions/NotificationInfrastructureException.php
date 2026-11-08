<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

/**
 * Инфраструктурная ошибка уведомления: повтор по backoff (FR-NTF-271).
 *
 * Примеры — недоступность SMTP, сбоя записи журнала фейкового push,
 * недоступность базы. Повтор возвращает ту же строку журнала. Код ответа
 * провайдера, если он известен каналу, сохраняется в журнале попыток.
 */
class NotificationInfrastructureException extends NotificationException
{
    public function __construct(
        private readonly string $reasonCode,
        string $message = '',
        ?\Throwable $previous = null,
        private readonly ?string $externalProviderCode = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->reasonCode;
    }

    public function providerCode(): ?string
    {
        return $this->externalProviderCode;
    }
}
