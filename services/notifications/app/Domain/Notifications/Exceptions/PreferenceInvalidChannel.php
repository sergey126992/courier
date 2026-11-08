<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * Канал предпочтений неизвестен или выключен в окружении (FR-NTF-454).
 *
 * Ответ API — `422 PREFERENCE_INVALID_CHANNEL`, ничего не сохраняется.
 */
final class PreferenceInvalidChannel extends NotificationBusinessException
{
    public function __construct()
    {
        parent::__construct(NotificationErrorCode::PREFERENCE_INVALID_CHANNEL, 'Некорректный канал.');
    }
}
