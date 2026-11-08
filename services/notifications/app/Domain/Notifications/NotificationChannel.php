<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Канал доставки уведомления (FR-NTF-143, FR-NTF-244).
 *
 * Значение `telegram` добавлено аддитивно (FR-NTF-431): CHECK по колонке
 * `notifications.channel` нет, поэтому миграция не нужна. Каналы события
 * независимы: сбой одного не отменяет другой.
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
