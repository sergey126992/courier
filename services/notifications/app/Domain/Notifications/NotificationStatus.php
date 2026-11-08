<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Состояние записи журнала уведомлений сервиса (ЧТЗ 5.3, FR-NTF-148).
 *
 * Значения совпадают с CHECK базы: база защищает набор состояний, домен —
 * допустимые переходы (ЧТЗ 7, п. 4). «Отправлено» и «не отправлено» —
 * терминальные состояния для автоматических повторов.
 *
 * Переходы и их исполнители появляются вместе с потребителем и поллером
 * (пункты 12 и 16 плана M11); здесь достаточно набора состояний.
 */
enum NotificationStatus: string
{
    case Created = 'created';
    case Queued = 'queued';
    case Sent = 'sent';
    case Error = 'error';
    case Failed = 'failed';

    /**
     * Терминальное состояние: автоматических переходов из него нет.
     */
    public function isTerminal(): bool
    {
        return $this === self::Sent || $this === self::Failed;
    }
}
