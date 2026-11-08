<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * Окно тихих часов нарушает формат или нулевой длины (FR-NTF-457, FR-NTF-458).
 *
 * Ответ API — `422 PREFERENCE_INVALID_QUIET_HOURS`, ничего не сохраняется.
 */
final class PreferenceInvalidQuietHours extends NotificationBusinessException
{
    public function __construct()
    {
        parent::__construct(NotificationErrorCode::PREFERENCE_INVALID_QUIET_HOURS, 'Некорректное окно тихих часов.');
    }
}
