<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationErrorCode;
use Throwable;

/**
 * Инфраструктурный сбой отправки по каналу (FR-NTF-256, FR-NTF-271).
 *
 * Примеры — недоступность SMTP и сбой записи журнала фейкового push.
 * Повтор по backoff возвращает ту же строку журнала, поэтому исключение
 * инфраструктурное. В сообщении только канал: адресов и тел нет.
 */
final class NotificationDeliveryFailed extends NotificationInfrastructureException
{
    public static function make(NotificationChannel $channel, ?Throwable $previous = null): self
    {
        return new self(
            NotificationErrorCode::SEND_FAILED,
            sprintf('Отправка уведомления по каналу «%s» не выполнена.', $channel->value),
            $previous,
        );
    }
}
