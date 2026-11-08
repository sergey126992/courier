<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DlqEntry;

/**
 * Запись DLQ в ответе API (ЧТЗ 8.4, FR-NTF-502, FR-NTF-505).
 *
 * В ответе только снимок без персональных данных: идентификаторы, событие,
 * канал, состояние разбора, класс и код причины, число попыток и моменты
 * времени. Адресов, телефонов, `chat_id`, текстов сообщений и токенов нет.
 */
final class DlqEntryResource
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(DlqEntry $entry): array
    {
        return [
            'id' => (int) $entry->getKey(),
            'notification_id' => (int) $entry->notification_id,
            'event_id' => (string) $entry->event_id,
            'event_type' => (string) $entry->event_type,
            'order_id' => $entry->order_id,
            'channel' => (string) $entry->channel,
            'state' => $entry->state->value,
            'error_class' => $entry->error_class,
            'error_code' => $entry->error_code,
            'attempts' => (int) $entry->attempts,
            'replay_count' => (int) $entry->replay_count,
            'failed_at' => $entry->failed_at->toIso8601String(),
        ];
    }

    /**
     * Ответ на повтор: состояние и счётчик без персональных данных.
     *
     * @return array<string, mixed>
     */
    public static function replay(DlqEntry $entry): array
    {
        return [
            'id' => (int) $entry->getKey(),
            'notification_id' => (int) $entry->notification_id,
            'state' => $entry->state->value,
            'replay_count' => (int) $entry->replay_count,
            'last_replayed_at' => $entry->last_replayed_at?->toIso8601String(),
        ];
    }
}
