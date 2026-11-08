<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Notifications\NotificationErrorCode;
use App\Domain\Notifications\NotificationStatus;
use App\Logging\TechnicalLogger;
use App\Models\Notification;
use App\Models\OutboxMessage;
use DateTimeInterface;

/**
 * Обратное событие о результате доставки в Outbox сервиса (FR-NTF-281…286).
 *
 * Строка Outbox пишется в той же транзакции, что смена статуса уведомления:
 * факт отправки и событие о нём либо фиксируются вместе, либо не фиксируются
 * вовсе. Прямого `XADD` здесь нет — публикацию выполняет публикатор Outbox,
 * поэтому недоступный Redis не отменяет отправленное уведомление (FR-NTF-286).
 *
 * Payload повторяет ЧТЗ 8.3: идентификатор исходного события заказа,
 * уведомление, заказ, получатель, канал, статус, номер попытки, момент и
 * correlation_id. Персональных данных в событии нет (FR-NTF-282, FR-NTF-235).
 */
final class NotificationEventOutbox
{
    public function __construct(private readonly TechnicalLogger $logger) {}

    /**
     * Тип события успешной отправки.
     */
    public const EVENT_SENT = 'notification.sent';

    /**
     * Тип события окончательного отказа.
     */
    public const EVENT_FAILED = 'notification.failed';

    /**
     * Владелец агрегата в конверте обратного события.
     */
    public const AGGREGATE_TYPE = 'notification';

    /**
     * Версия контракта обратных событий.
     */
    private const SCHEMA_VERSION = 'v1';

    /**
     * Пишет обратное событие для терминального статуса уведомления.
     *
     * Не терминальные состояния (`created`, `queued`, `error`) событий не
     * порождают: повтор по backoff ещё не является результатом доставки.
     */
    public function enqueue(
        Notification $notification,
        NotificationStatus $status,
        int $attempt,
        ?string $errorCode,
    ): void {
        if (! $status->isTerminal()) {
            return;
        }

        $orderId = $notification->order_id;

        if ($orderId === null || $orderId < 1) {
            // Событие заказа всегда несёт заказ; строка без него — нарушение
            // инварианта журнала. Статус не откатывается, причина в журнал
            // пишется без персональных данных (FR-NTF-286).
            $this->logger->warning('Обратное событие не создано: у уведомления нет заказа.', [
                'correlation_id' => $notification->correlation_id,
                'notification_id' => (int) $notification->getKey(),
                'channel' => (string) $notification->channel,
                'error_code' => NotificationErrorCode::PROGRAM_ERROR,
            ]);

            return;
        }

        $occurredAt = now()->utc();
        $correlationId = $this->correlationId($notification);

        $payload = [
            'event_id' => $notification->event_id,
            'notification_id' => (int) $notification->getKey(),
            'order_id' => $orderId,
            'recipient_id' => (int) $notification->recipient_id,
            'channel' => (string) $notification->channel,
            'status' => $status->value,
            'attempt' => max(1, $attempt),
            'occurred_at' => $occurredAt->format(DateTimeInterface::ATOM),
            'correlation_id' => $correlationId,
        ];

        if ($status === NotificationStatus::Failed) {
            $payload['error_code'] = $errorCode ?? NotificationErrorCode::SEND_FAILED;
        }

        OutboxMessage::query()->create([
            'event_type' => $status === NotificationStatus::Sent ? self::EVENT_SENT : self::EVENT_FAILED,
            'schema_version' => self::SCHEMA_VERSION,
            'aggregate_type' => self::AGGREGATE_TYPE,
            'aggregate_id' => (int) $notification->getKey(),
            'payload' => $payload,
            'correlation_id' => $correlationId,
            'published_at' => null,
            'attempts' => 0,
            'last_error' => null,
            'available_at' => $occurredAt,
        ]);
    }

    /**
     * Сквозной идентификатор: исходного события заказа, иначе — его event_id.
     *
     * Строки, созданные до M11, correlation_id не имеют; event_id остаётся
     * стабильной неличной ссылкой на событие-источник (FR-OPS-201).
     */
    private function correlationId(Notification $notification): string
    {
        $correlationId = $notification->correlation_id;

        return is_string($correlationId) && trim($correlationId) !== ''
            ? $correlationId
            : $notification->event_id;
    }
}
