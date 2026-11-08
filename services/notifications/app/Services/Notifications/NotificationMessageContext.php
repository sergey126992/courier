<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\Notification;

/**
 * Данные подстановок в тексте уведомления (FR-NTF-251).
 *
 * Строка журнала хранит заказ, статус события и код трекинга, поэтому текст
 * собирается без доступа к базе монолита и без события: номер заказа, статус
 * и ссылка трекинга. Персональных данных среди подстановок нет.
 */
final class NotificationMessageContext
{
    public function __construct(private readonly NotificationTexts $texts) {}

    /**
     * @return array<string, string>
     */
    public function replacements(Notification $notification): array
    {
        return [
            'number' => $notification->order_id === null ? '' : (string) $notification->order_id,
            'status' => $this->status((string) $notification->event_type),
            'tracking_url' => $this->texts->trackingUrl($notification->tracking_code),
        ];
    }

    /**
     * Статус для текста: часть типа события после точки (`order.delivered` → `delivered`).
     */
    private function status(string $eventType): string
    {
        $position = strrpos($eventType, '.');

        return $position === false ? $eventType : substr($eventType, $position + 1);
    }
}
