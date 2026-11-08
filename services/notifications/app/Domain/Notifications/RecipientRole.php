<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

/**
 * Роль получателя уведомления (FR-NTF-141, FR-NTF-242).
 *
 * Автоматические уведомления получают отправитель заказа и курьер
 * назначения. Роли пользователей доступа и роли получателя — разные понятия.
 */
enum RecipientRole: string
{
    case Sender = 'sender';
    case Courier = 'courier';

    /**
     * Человекочитаемое название роли.
     */
    public function label(): string
    {
        return match ($this) {
            self::Sender => 'Отправитель',
            self::Courier => 'Курьер',
        };
    }
}
