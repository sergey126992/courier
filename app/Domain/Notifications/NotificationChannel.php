<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Канал доставки уведомления (FR-NTF-143, FR-NTF-405).
 *
 * Нужен фильтру и подписям админ-страниц: каналы приходят из API сервиса
 * оповещений, монолит их не создаёт и не выбирает (FR-NTF-406).
 */
enum NotificationChannel: string
{
    case Email = 'email';
    case Push = 'push';
    case Telegram = 'telegram';

    /**
     * Человекочитаемое название канала.
     */
    public function label(): string
    {
        return match ($this) {
            self::Email => 'Электронная почта',
            self::Push => 'Push-уведомление',
            self::Telegram => 'Telegram',
        };
    }
}
