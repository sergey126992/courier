<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * Telegram не нашёл чат или бот заблокирован пользователем (FR-NTF-437, FR-NTF-439).
 *
 * Бизнес-ошибка канала: привязка отзывается, строка журнала переходит
 * в `failed` с кодом `TELEGRAM_CHAT_NOT_FOUND`, повтор не назначается.
 * В сообщении нет `chat_id`, токена и текста сообщения (FR-NTF-419).
 */
final class TelegramChatNotFound extends NotificationBusinessException
{
    public function __construct()
    {
        parent::__construct(
            NotificationErrorCode::TELEGRAM_CHAT_NOT_FOUND,
            'Telegram сообщил, что чат получателя недоступен.',
        );
    }
}
