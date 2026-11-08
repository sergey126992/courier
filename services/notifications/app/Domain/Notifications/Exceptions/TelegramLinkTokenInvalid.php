<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * Токен привязки не найден, уже использован или аннулирован (FR-NTF-412, 414).
 *
 * Повторное подтверждение не меняет существующую привязку: ответ API — `409`.
 */
final class TelegramLinkTokenInvalid extends NotificationBusinessException
{
    public function __construct()
    {
        parent::__construct(NotificationErrorCode::TELEGRAM_LINK_TOKEN_INVALID, 'Токен привязки недействителен.');
    }
}
