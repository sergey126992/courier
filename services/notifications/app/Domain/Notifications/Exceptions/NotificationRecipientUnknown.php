<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * Контакт получателя неизвестен (FR-NTF-236).
 *
 * Бизнес-ошибка: в `recipient_contacts` нет адреса, поэтому письмо отправить
 * некуда. Повтор той же операции даст тот же результат, поэтому уведомление
 * переходит в `failed` без автоматического повтора (FR-NTF-252, FR-NTF-271).
 * В сообщении только роль получателя: email, имени и телефона нет.
 */
final class NotificationRecipientUnknown extends NotificationBusinessException
{
    public static function make(string $recipientRole): self
    {
        return new self(
            NotificationErrorCode::RECIPIENT_UNKNOWN,
            sprintf('Контакт получателя с ролью «%s» неизвестен сервису.', $recipientRole),
        );
    }
}
