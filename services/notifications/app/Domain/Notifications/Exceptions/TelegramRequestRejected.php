<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * Прочий детерминированный отказ Bot API с кодом 4xx.
 *
 * Бизнес-ошибка без повтора: тот же запрос даст тот же ответ. Код безопасен
 * и не выдаёт персональных данных; привязка при таком отказе не отзывается
 * (FR-NTF-438, FR-NTF-439).
 */
final class TelegramRequestRejected extends NotificationBusinessException
{
    public function __construct()
    {
        parent::__construct(
            NotificationErrorCode::TELEGRAM_REQUEST_REJECTED,
            'Telegram отклонил запрос отправки сообщения.',
        );
    }
}
