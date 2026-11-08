<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Состояние записи очереди необработанных (DLQ) сервиса (ЧТЗ 5.2, FR-NTF-507).
 *
 * Значения совпадают с CHECK базы: база защищает набор состояний, домен —
 * допустимые переходы (ЧТЗ 7.3, п. 5). Состояние «в работе» отдельной
 * записью не хранится: работу с уведомлением показывает строка журнала.
 *
 * Переходы: `open` → `replayed` (повтор поставлен), `replayed` → `resolved`
 * (успешная отправка) или `open` (новый терминальный отказ). Запись
 * в состоянии `resolved` терминальна, повтор из неё отклоняется.
 */
enum DlqState: string
{
    case Open = 'open';
    case Replayed = 'replayed';
    case Resolved = 'resolved';

    /**
     * Терминальное состояние: переходов и повторов из него нет.
     */
    public function isTerminal(): bool
    {
        return $this === self::Resolved;
    }

    /**
     * Повтор допустим только для открытой записи: разобранная запись
     * отклоняется кодом `DLQ_ENTRY_ALREADY_RESOLVED`, запись «уже в повторе» —
     * конфликтом состояния (FR-NTF-506, FR-NTF-512).
     */
    public function canReplay(): bool
    {
        return $this === self::Open;
    }

    /**
     * Запись участвует в глубине DLQ: разобранные не учитываются (FR-OPS-504).
     */
    public function isUnresolved(): bool
    {
        return $this !== self::Resolved;
    }
}
