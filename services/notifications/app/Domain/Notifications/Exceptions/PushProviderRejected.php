<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * Провайдер push отклонил сообщение по неисправимой бизнес-причине
 * (FR-NTF-523, ЧТЗ 8.3).
 *
 * Бизнес-ошибка без повтора: повтор того же запроса даст тот же ответ.
 * Запись DLQ сохраняет код причины; персональных данных в сообщении нет.
 */
final class PushProviderRejected extends NotificationBusinessException
{
    public static function status(int $status): self
    {
        return new self(
            NotificationErrorCode::PUSH_PROVIDER_REJECTED,
            sprintf('Провайдер push отклонил сообщение: HTTP %d.', $status),
        );
    }
}
