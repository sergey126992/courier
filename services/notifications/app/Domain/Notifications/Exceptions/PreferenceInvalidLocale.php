<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * Локаль предпочтений не поддерживается сервисом (FR-NTF-454).
 *
 * Ответ API — `422 PREFERENCE_INVALID_LOCALE`, ничего не сохраняется.
 */
final class PreferenceInvalidLocale extends NotificationBusinessException
{
    public function __construct()
    {
        parent::__construct(NotificationErrorCode::PREFERENCE_INVALID_LOCALE, 'Некорректная локаль.');
    }
}
