<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * Тип события известен сервису, но недоступен роли получателя (FR-NTF-460).
 *
 * Наборы типов событий по ролям задаются конфигурацией сервиса; ответ API —
 * `422 PREFERENCE_EVENT_TYPE_NOT_ALLOWED`, ничего не сохраняется.
 */
final class PreferenceEventTypeNotAllowed extends NotificationBusinessException
{
    public function __construct()
    {
        parent::__construct(
            NotificationErrorCode::PREFERENCE_EVENT_TYPE_NOT_ALLOWED,
            'Тип события недоступен роли получателя.',
        );
    }
}
