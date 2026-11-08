<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationErrorCode;

/**
 * Детерминированный отказ провайдера канала (FR-NTF-477).
 *
 * Например, фейковый push-провайдер в тестах отказывает выбранному
 * получателю без сети. Повтор той же операции даст тот же результат,
 * поэтому это бизнес-ошибка: строка переходит в `failed`, а служба
 * переходит к следующему каналу цепочки (FR-NTF-477, FR-NTF-482).
 * В сообщении только канал: адресов и идентификаторов получателя нет.
 */
final class NotificationProviderRejected extends NotificationBusinessException
{
    public function __construct(NotificationChannel $channel)
    {
        parent::__construct(
            NotificationErrorCode::NOTIFICATION_PROVIDER_REJECTED,
            sprintf('Провайдер канала «%s» детерминированно отказал в доставке.', $channel->value),
        );
    }
}
