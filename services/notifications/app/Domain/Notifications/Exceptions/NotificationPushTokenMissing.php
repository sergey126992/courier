<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * У получателя нет активного device-токена (FR-NTF-528).
 *
 * Это не ошибка отправки: применяется цепочка замещения каналов M12, а запись
 * DLQ по причине «нет токена» не создаётся. Бизнес-класс без повтора той же
 * операции: повтор по отсутствующему токену даст тот же результат.
 */
final class NotificationPushTokenMissing extends NotificationBusinessException
{
    public function __construct()
    {
        parent::__construct(
            NotificationErrorCode::NOTIFICATION_PUSH_TOKEN_MISSING,
            'У получателя нет активного device-токена для push.',
        );
    }
}
