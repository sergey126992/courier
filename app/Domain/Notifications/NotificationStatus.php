<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Состояние записи журнала уведомлений сервиса (FR-NTF-148).
 *
 * Нужно подписям админ-страниц: строки приходят из API сервиса оповещений,
 * а страница показывает человекочитаемое название состояния (FR-NTF-405).
 * Значения совпадают с CHECK базы сервиса.
 */
enum NotificationStatus: string
{
    case Created = 'created';
    case Queued = 'queued';
    case Sent = 'sent';
    case Error = 'error';
    case Failed = 'failed';

    /**
     * Человекочитаемое название состояния.
     */
    public function label(): string
    {
        return match ($this) {
            self::Created => 'Создано',
            self::Queued => 'В очереди',
            self::Sent => 'Отправлено',
            self::Error => 'Ошибка отправки',
            self::Failed => 'Не отправлено',
        };
    }
}
