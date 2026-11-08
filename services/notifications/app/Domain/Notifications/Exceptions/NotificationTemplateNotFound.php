<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Events\EventType;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationErrorCode;

/**
 * Шаблон пары «событие — канал» не предусмотрен (FR-NTF-247).
 *
 * Бизнес-ошибка: повтор не поможет, пока не появится шаблон. В сообщении
 * только тип события и канал — персональных данных нет (FR-NTF-235).
 */
final class NotificationTemplateNotFound extends NotificationBusinessException
{
    public static function make(EventType $eventType, NotificationChannel $channel): self
    {
        return new self(
            NotificationErrorCode::TEMPLATE_NOT_FOUND,
            sprintf(
                'Шаблон уведомления для события «%s» и канала «%s» не предусмотрен.',
                $eventType->value,
                $channel->value,
            ),
        );
    }
}
