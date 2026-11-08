<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * Критичное событие нельзя оставить без email (FR-NTF-455).
 *
 * Ответ API — `422 PREFERENCE_CRITICAL_CHANNEL_REQUIRED`: пользователь может
 * добавить push и Telegram, но email критичного события остаётся всегда.
 */
final class PreferenceCriticalChannelRequired extends NotificationBusinessException
{
    public function __construct()
    {
        parent::__construct(
            NotificationErrorCode::PREFERENCE_CRITICAL_CHANNEL_REQUIRED,
            'Email критичного события отключить нельзя.',
        );
    }
}
