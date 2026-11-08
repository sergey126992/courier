<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

/**
 * Бизнес-ошибка уведомления: автоматического повтора нет (FR-NTF-271).
 *
 * Примеры — нет шаблона пары «событие — канал» или нет контакта получателя:
 * повтор той же операции даст тот же результат, поэтому уведомление
 * переходит в `failed` без backoff.
 */
class NotificationBusinessException extends NotificationException
{
    public function __construct(
        private readonly string $reasonCode,
        string $message = '',
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reasonCode;
    }
}
