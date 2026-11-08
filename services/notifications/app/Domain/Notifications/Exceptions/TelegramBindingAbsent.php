<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * Активной привязки Telegram для отвязки нет (FR-NTF-417).
 *
 * Ответ API — `409`: повторная отвязка ничего не меняет.
 */
final class TelegramBindingAbsent extends NotificationBusinessException
{
    public function __construct()
    {
        parent::__construct(NotificationErrorCode::TELEGRAM_BINDING_ABSENT, 'Активной привязки Telegram нет.');
    }
}
