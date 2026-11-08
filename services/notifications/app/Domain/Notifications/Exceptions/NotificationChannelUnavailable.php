<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationErrorCode;

/**
 * Канал недоступен заранее (FR-NTF-440, FR-NTF-476).
 *
 * Бизнес-ошибка: повтора нет, строка журнала переходит в `failed`
 * с кодом `NOTIFICATION_CHANNEL_UNAVAILABLE`. Так выглядит отсутствующая
 * активная привязка канала и пустая цепочка каналов предпочтений.
 * В сообщении только канал: адреса и идентификаторов чата нет (FR-NTF-419).
 */
final class NotificationChannelUnavailable extends NotificationBusinessException
{
    public function __construct(NotificationChannel $channel)
    {
        parent::__construct(
            NotificationErrorCode::NOTIFICATION_CHANNEL_UNAVAILABLE,
            sprintf('Канал уведомления «%s» недоступен заранее.', $channel->value),
        );
    }
}
