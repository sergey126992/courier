<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PushMessage;

/**
 * Сообщение фейкового push-провайдера для симулятора (FR-NTF-254).
 *
 * Адресов и device-токенов нет: только идентификатор уведомления, тело,
 * отправленное шаблоном, и момент записи.
 */
final class PushMessageResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(PushMessage $message): array
    {
        return [
            'id' => (int) $message->getKey(),
            'notification_id' => $message->notification_id,
            'template' => (string) $message->template,
            'body' => (string) $message->body,
            'sent_at' => $message->sent_at?->toIso8601String(),
        ];
    }
}
