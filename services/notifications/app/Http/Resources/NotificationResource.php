<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Notification;

/**
 * Строка журнала уведомлений в ответе API (ЧТЗ 8.6, FR-NTF-275).
 *
 * В ответе только идентификаторы, канал, шаблон, статус, попытки, коды
 * причин и моменты времени. Адресов, телефонов и прочих персональных
 * данных нет, поэтому ресурс можно отдавать служебным клиентам.
 */
final class NotificationResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Notification $notification): array
    {
        return [
            'id' => (int) $notification->getKey(),
            'event_id' => (string) $notification->event_id,
            'order_id' => $notification->order_id,
            'recipient_id' => $notification->recipient_id,
            'recipient_role' => (string) $notification->recipient_role,
            'channel' => (string) $notification->channel,
            'event_type' => (string) $notification->event_type,
            'template' => (string) $notification->template,
            'status' => $notification->status->value,
            'attempts' => $notification->attempts,
            'error_code' => $notification->last_error_code,
            'created_at' => $notification->created_at?->toIso8601String(),
            'updated_at' => $notification->updated_at?->toIso8601String(),
            'sent_at' => $notification->sent_at?->toIso8601String(),
        ];
    }
}
