<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * Тип события предпочтений неизвестен сервису (FR-NTF-454).
 *
 * Ответ API — `422 PREFERENCE_INVALID_EVENT_TYPE`, ничего не сохраняется.
 */
final class PreferenceInvalidEventType extends NotificationBusinessException
{
    public function __construct()
    {
        parent::__construct(NotificationErrorCode::PREFERENCE_INVALID_EVENT_TYPE, 'Неизвестный тип события.');
    }
}
