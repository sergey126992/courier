<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Notifications\DlqState;
use App\Domain\Notifications\NotificationErrorCode;
use App\Domain\Notifications\NotificationStatus;
use App\Models\DlqEntry;
use App\Models\Notification;

/**
 * Запись очереди необработанных при терминальном статусе (FR-NTF-501…507).
 *
 * Вызывается из `NotificationSender::finalize()` внутри той же транзакции,
 * что смена статуса и запись Outbox: либо все три эффекта фиксируются вместе,
 * либо ни один. При `failed` запись по каналу создаётся или возвращается в
 * `open` с обновлённым снимком; при `sent` — переводится в `resolved`.
 *
 * Дедупликация держится уникальным индексом `(notification_id, channel)`:
 * вставка идёт `upsert`, проверки «есть ли уже» нет (FR-NTF-509). Счётчик
 * повторов и момент последнего повтора при новом отказе не сбрасываются:
 * история повторов остаётся в записи (FR-NTF-507, ЧТЗ 7.3 п. 7).
 *
 * Снимок не содержит тела сообщения, email, телефона и токена: причина
 * остаётся классом и кодом ошибки (FR-NTF-502, ЧТЗ 7.3 п. 4).
 */
final class DlqEntryWriter
{
    /**
     * Применяет терминальный статус к записи DLQ по каналу.
     */
    public function record(
        Notification $notification,
        NotificationStatus $status,
        int $attempt,
        ?string $errorClass,
        ?string $errorCode,
    ): void {
        if ($status === NotificationStatus::Failed) {
            // «Нет device-токена» — не отказ канала, а штатное замещение:
            // запись DLQ по этой причине не создаётся (FR-NTF-528).
            if ($errorCode === NotificationErrorCode::NOTIFICATION_PUSH_TOKEN_MISSING) {
                return;
            }

            $this->open($notification, $attempt, $errorClass, $errorCode);

            return;
        }

        if ($status === NotificationStatus::Sent) {
            $this->resolve($notification);
        }
    }

    /**
     * Создаёт запись при отказе или возвращает существующую в `open`.
     *
     * `failed_at` берётся из транзакции отказа: момент отказа один для
     * журнала и для снимка (ЧТЗ 7.3 п. 2).
     */
    private function open(
        Notification $notification,
        int $attempt,
        ?string $errorClass,
        ?string $errorCode,
    ): void {
        $now = now()->utc();
        $failedAt = $now->format('Y-m-d H:i:sP');

        DlqEntry::query()->upsert(
            [[
                'notification_id' => (int) $notification->getKey(),
                'channel' => (string) $notification->channel,
                'state' => DlqState::Open->value,
                'event_id' => $notification->event_id,
                'event_type' => $notification->event_type,
                'order_id' => $notification->order_id,
                'recipient_id' => (int) $notification->recipient_id,
                'recipient_role' => $notification->recipient_role,
                'error_class' => $errorClass,
                'error_code' => $errorCode,
                'attempts' => $attempt,
                'correlation_id' => $notification->correlation_id,
                'failed_at' => $failedAt,
                'resolved_at' => null,
            ]],
            ['notification_id', 'channel'],
            [
                'state',
                'event_id',
                'event_type',
                'order_id',
                'recipient_id',
                'recipient_role',
                'error_class',
                'error_code',
                'attempts',
                'correlation_id',
                'failed_at',
                'resolved_at',
            ],
        );
    }

    /**
     * Закрывает запись канала: успех в той же транзакции, что `sent`.
     */
    private function resolve(Notification $notification): void
    {
        $now = now()->utc();
        $formatted = $now->format('Y-m-d H:i:sP');

        DlqEntry::query()
            ->where('notification_id', (int) $notification->getKey())
            ->where('channel', (string) $notification->channel)
            ->update([
                'state' => DlqState::Resolved->value,
                'resolved_at' => $formatted,
                'updated_at' => $formatted,
            ]);
    }
}
