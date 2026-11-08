<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Exceptions;

use App\Domain\Notifications\DlqState;
use App\Domain\Notifications\NotificationErrorCode;

/**
 * Повтор записи DLQ недопустим в текущем состоянии (FR-NTF-506, ЧТЗ 8.3).
 *
 * Повторять можно только открытую запись: `replayed` уже стоит в очереди
 * поллера, `resolved` разобран. Второго эффекта нет; сообщение содержит
 * состояние, персональных данных в нём нет.
 */
final class DlqEntryNotReplayable extends NotificationBusinessException
{
    public static function make(DlqState $state): self
    {
        return new self(
            NotificationErrorCode::DLQ_ENTRY_ALREADY_RESOLVED,
            sprintf('Запись в состоянии «%s» повтору не подлежит.', $state->value),
        );
    }
}
