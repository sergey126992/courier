<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Состояние сообщения в Inbox сервиса (ЧТЗ 5.2, FR-NTF-225).
 *
 * «В работе» отдельной колонкой не хранится: это pending-запись consumer
 * group. В журнале фиксируются только терминальные состояния — обработано
 * и недействительно; недействительное сообщение подтверждается без эффекта.
 */
enum InboxStatus: string
{
    case Processed = 'processed';
    case Invalid = 'invalid';
}
