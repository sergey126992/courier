<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Events\EventType;
use App\Domain\Notifications\FallbackChain;
use App\Domain\Notifications\NotificationChannel;
use App\Domain\Notifications\NotificationStatus;
use App\Domain\Notifications\NotificationTemplate;
use App\Logging\TechnicalLogger;
use App\Models\Notification;
use App\Services\Notifications\Channels\ChannelAvailability;
use Illuminate\Support\Facades\DB;

/**
 * Замещение недоступного канала следующим (FR-NTF-477…482, ЧТЗ 5.4).
 *
 * Вызывается после терминального отказа строки: если причина детерминированная
 * (нет чата Telegram, отозванная привязка, детерминированный отказ провайдера),
 * исходная строка остаётся `failed`, а служба создаёт строку следующего канала
 * цепочки в состоянии `created` со ссылкой `fallback_from_notification_id`.
 *
 * Инфраструктурные сбои (`429`, `5xx`, таймаут) сюда не попадают: их лечит
 * повтор по backoff, а замещение канала при временном сбое доставило бы письмо
 * вместо ожидаемого сообщения (решение 12.1 по вопросу 1).
 *
 * Повторная обработка события второго эффекта не даёт: ключ дедупликации
 * `(event_id, recipient_id, channel)` захватывается до создания строки, поэтому
 * вторая строка того же канала не появляется (FR-NTF-478, сценарий 16).
 * Цепочка ограничена `notifications.preferences.max_fallback_depth`, а глубина
 * считается по ссылкам, а не отдельной колонкой (FR-NTF-479).
 */
final class ChannelFallback
{
    public function __construct(
        private readonly ChannelAvailability $availability,
        private readonly NotificationCounters $counters,
        private readonly TechnicalLogger $logger,
    ) {}

    /**
     * Создаёт строку следующего канала после детерминированного отказа.
     *
     * @return Notification|null null — замещение не нужно или невозможно
     */
    public function handle(Notification $notification, string $errorCode): ?Notification
    {
        if (! $this->triggersFallback($errorCode)) {
            return null;
        }

        $current = NotificationChannel::tryFrom((string) $notification->channel);
        $eventType = EventType::tryFrom((string) $notification->event_type);

        if ($current === null || $eventType === null) {
            return null;
        }

        $next = $this->nextChannel($notification, $current, $eventType);

        if ($next === null) {
            // Конец цепочки: email последний, доступного следующего канала
            // нет. Исходная строка остаётся `failed` (FR-NTF-482).
            return null;
        }

        if (! FallbackChain::hasRoom($this->depth($notification), $this->maxDepth())) {
            return null;
        }

        $created = $this->createRow($notification, $next, $eventType);

        if ($created === null) {
            // Второй строки канала нет: событие уже обработано (FR-NTF-478),
            // счётчик замещения не увеличивается.
            return null;
        }

        // Счётчик и журнал в момент события (FR-OPS-413): только каналы,
        // идентификаторы и тип события, без `chat_id`, адресов и текстов.
        $this->counters->recordFallback($current->value);

        $this->logger->info('Канал уведомления заменён следующим.', [
            'correlation_id' => $notification->correlation_id,
            'notification_id' => (int) $notification->getKey(),
            'user_id' => (int) $notification->recipient_id,
            'channel' => $next->value,
            'event_type' => $eventType->value,
            'fallback_from' => $current->value,
        ]);

        return $created;
    }

    /**
     * Детерминированный ли отказ: только такой запускает замещение.
     */
    private function triggersFallback(string $errorCode): bool
    {
        foreach ((array) config('notifications.preferences.fallback_error_codes', []) as $value) {
            if (is_string($value) && trim($value) === $errorCode) {
                return true;
            }
        }

        return false;
    }

    /**
     * Следующий доступный канал после текущего, или null в конце цепочки.
     */
    private function nextChannel(
        Notification $notification,
        NotificationChannel $current,
        EventType $eventType,
    ): ?NotificationChannel {
        $started = false;

        foreach ($this->chain() as $channel) {
            if (! $started) {
                $started = $channel === $current;

                continue;
            }

            if ($channel === $current) {
                continue;
            }

            // Канал без текста для события бессмысленен: пропускаем,
            // как и канал, недоступный заранее (FR-NTF-474).
            if (! NotificationTemplate::has($eventType, $channel)) {
                continue;
            }

            if ($this->availability->filterAvailable((int) $notification->recipient_id, [$channel]) !== []) {
                return $channel;
            }
        }

        return null;
    }

    /**
     * Цепочка каналов из конфигурации: email всегда последний (FR-NTF-472, 482).
     *
     * @return list<NotificationChannel>
     */
    private function chain(): array
    {
        $channels = [];

        foreach ((array) config('notifications.preferences.channel_priority', []) as $value) {
            $channel = NotificationChannel::tryFrom((string) $value);

            if ($channel !== null && $channel !== NotificationChannel::Email && ! in_array($channel, $channels, true)) {
                $channels[] = $channel;
            }
        }

        $channels[] = NotificationChannel::Email;

        return $channels;
    }

    /**
     * Глубина строки: число ссылок `fallback_from_notification_id` до корня.
     */
    private function depth(Notification $notification): int
    {
        $id = (int) $notification->getKey();
        $links = [];
        $current = $id;
        $limit = $this->maxDepth() + 1;

        for ($step = 0; $step <= $limit; $step++) {
            $row = DB::table('notifications')
                ->where('id', $current)
                ->first(['id', 'fallback_from_notification_id']);

            if ($row === null) {
                break;
            }

            $parent = $row->fallback_from_notification_id;
            $links[$current] = $parent === null ? null : (int) $parent;

            if ($parent === null) {
                break;
            }

            $current = (int) $parent;
        }

        return FallbackChain::depth($id, $links);
    }

    /**
     * Предел глубины цепочки из конфигурации (FR-NTF-479).
     */
    private function maxDepth(): int
    {
        return max(1, (int) config('notifications.preferences.max_fallback_depth', 3));
    }

    /**
     * Строка следующего канала в `created` со ссылкой на исходную строку.
     *
     * Ключ дедупликации захватывается до вставки: занятый ключ означает, что
     * строка канала уже создана (или событие обработано повторно), и второго
     * эффекта не будет (FR-NTF-478).
     */
    private function createRow(
        Notification $notification,
        NotificationChannel $channel,
        EventType $eventType,
    ): ?Notification {
        $sourceId = (int) $notification->getKey();
        $eventId = (string) $notification->event_id;
        $recipientId = (int) $notification->recipient_id;
        $now = now()->utc()->format('Y-m-d H:i:sP');

        return DB::transaction(function () use ($notification, $channel, $eventType, $sourceId, $eventId, $recipientId, $now): ?Notification {
            $captured = DB::table('notification_dedup')->insertOrIgnore([
                'event_id' => $eventId,
                'recipient_id' => $recipientId,
                'channel' => $channel->value,
                'notification_id' => null,
                'created_at' => $now,
            ]);

            if ($captured === 0) {
                return null;
            }

            $id = DB::table('notifications')->insertGetId([
                'event_id' => $eventId,
                'correlation_id' => $notification->correlation_id,
                'order_id' => $notification->order_id,
                'tracking_code' => $notification->tracking_code,
                'recipient_id' => $recipientId,
                'recipient_role' => (string) $notification->recipient_role,
                'channel' => $channel->value,
                'event_type' => (string) $notification->event_type,
                'template' => NotificationTemplate::for($eventType, $channel)->value,
                'status' => NotificationStatus::Created->value,
                'attempts' => 0,
                'last_error_code' => null,
                'fallback_from_notification_id' => $sourceId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('notification_dedup')
                ->where('event_id', $eventId)
                ->where('recipient_id', $recipientId)
                ->where('channel', $channel->value)
                ->update(['notification_id' => $id]);

            return Notification::query()->find($id);
        });
    }
}
