<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * Срок действия токена привязки истёк (FR-NTF-412).
 *
 * Ответ API — `410`: пользователю нужен новый токен из настроек.
 */
final class TelegramLinkTokenExpired extends NotificationBusinessException
{
    public function __construct()
    {
        parent::__construct(NotificationErrorCode::TELEGRAM_LINK_TOKEN_EXPIRED, 'Срок действия токена привязки истёк.');
    }
}
