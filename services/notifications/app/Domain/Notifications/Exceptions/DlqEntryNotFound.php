<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\NotificationErrorCode;

/**
 * Запись DLQ или строка журнала для повтора не найдены (ЧТЗ 8.3, `404`).
 *
 * Возникает, когда записи нет по идентификатору либо строка `notifications`
 * уже очищена по сроку хранения: запись DLQ переживает журнал (ЧТЗ 7.1).
 * Сообщение несёт только идентификатор записи — персональных данных нет.
 */
final class DlqEntryNotFound extends NotificationException
{
    public static function make(int $entryId): self
    {
        return new self(sprintf('Запись очереди необработанных %d не найдена.', $entryId));
    }

    public function errorCode(): string
    {
        return NotificationErrorCode::DLQ_ENTRY_NOT_FOUND;
    }
}
