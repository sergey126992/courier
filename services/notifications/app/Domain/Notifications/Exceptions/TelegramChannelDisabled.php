<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * Канал Telegram выключен в окружении (FR-NTF-411, ЧТЗ 8.3).
 *
 * Ответ API — `422`: токен не выпускается, привязка не создаётся.
 */
final class TelegramChannelDisabled extends NotificationBusinessException
{
    public function __construct()
    {
        parent::__construct(NotificationErrorCode::TELEGRAM_CHANNEL_DISABLED, 'Канал Telegram выключен.');
    }
}
