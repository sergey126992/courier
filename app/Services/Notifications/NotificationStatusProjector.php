<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Domain\Events\EventEnvelope;
use App\Domain\Events\EventType;
use App\Services\Events\Inbox;
use App\Services\Events\InboxResult;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Проекция результата доставки уведомлений в карточке заказа (FR-NTF-284, ЧТЗ 7.2).
 *
 * Обратное событие сервиса (`notification.sent` / `notification.failed`)
 * применяется через Inbox монолита: «ровно один эффект» на пару «событие —
 * обработчик» держит `processed_messages`. Строка `order_notification_status`
 * пишется через `insertOrIgnore`: повторная доставка с тем же
 * `source_event_id` не создаёт вторую строку, проверки «есть ли уже» перед
 * вставкой нет (ЧТЗ 7, п. 10).
 *
 * Неизвестный тип события в потоке уведомлений не роняет потребитель: он
 * логируется и подтверждается без эффекта (ЧТЗ 8.4).
 */
final readonly class NotificationStatusProjector
{
    /**
     * Стабильное имя обработчика Inbox: по нему решается, обработано ли событие.
     */
    public const HANDLER = 'order-notification-status';

    /**
     * Код журнала для события, не относящегося к проекции.
     */
    private const IGNORED_CODE = 'EVENT_CONTRACT_UNKNOWN';

    public function __construct(private Inbox $inbox) {}

    /**
     * Применяет обратное событие; null — событие не относится к проекции.
     */
    public function project(EventEnvelope $event): ?InboxResult
    {
        $status = match ($event->eventType) {
            EventType::NotificationSent => 'sent',
            EventType::NotificationFailed => 'failed',
            default => null,
        };

        if ($status === null) {
            Log::warning('Событие потока уведомлений не относится к проекции; подтверждено без эффекта.', [
                'code' => self::IGNORED_CODE,
                'message_id' => $event->messageId,
                'event_type' => $event->eventType->value,
                // Сквозной идентификатор исходного запроса (FR-OPS-201).
                'correlation_id' => $event->correlationId,
            ]);

            return null;
        }

        $row = $this->row($event, $status);

        if ($row === null) {
            return null;
        }

        $result = $this->inbox->process(self::HANDLER, $event, static function () use ($row): void {
            DB::table('order_notification_status')->insertOrIgnore($row);
        });

        // Применение обратного события в журнале монолита: запись связывается
        // с исходным HTTP-запросом через correlation_id (FR-OPS-201).
        Log::info('Проекция статуса доставки применена.', [
            'correlation_id' => $event->correlationId,
            'event_id' => $event->messageId,
            'order_id' => $row['order_id'],
            'notification_id' => $row['notification_id'],
            'channel' => $row['channel'],
            'status' => $row['status'],
            'attempt' => $row['attempt'],
        ]);

        return $result;
    }

    /**
     * Строка проекции из payload события; null — обязательных полей нет.
     *
     * @return array<string, mixed>|null
     */
    private function row(EventEnvelope $event, string $status): ?array
    {
        $values = $event->payload->toArray();

        $orderId = $this->intValue($values['order_id'] ?? null);
        $notificationId = $this->intValue($values['notification_id'] ?? null);
        $channel = $this->stringValue($values['channel'] ?? null);

        if ($orderId === null || $notificationId === null || $channel === null) {
            Log::warning('Обратное событие без обязательных полей пропущено; сообщение подтверждается.', [
                'code' => self::IGNORED_CODE,
                'message_id' => $event->messageId,
                'event_type' => $event->eventType->value,
                'correlation_id' => $event->correlationId,
            ]);

            return null;
        }

        $attempt = $this->intValue($values['attempt'] ?? null) ?? 1;
        $now = now();

        return [
            'order_id' => $orderId,
            'notification_id' => $notificationId,
            'channel' => $channel,
            'status' => $status,
            'attempt' => max(1, $attempt),
            'error_code' => $status === 'failed'
                ? $this->stringValue($values['error_code'] ?? null)
                : null,
            // Идентификатор обратного события: повтор той же записи потока
            // не создаёт вторую строку проекции (ЧТЗ 7, п. 10).
            'source_event_id' => $event->messageId,
            // Момент оставляет сервис: приходит из события (ЧТЗ 7, п. 5).
            'occurred_at' => $this->occurredAt($values['occurred_at'] ?? null, $event->occurredAt),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Момент события из payload; некорректное значение — момент конверта.
     *
     * @param mixed $value значение поля payload
     */
    private function occurredAt(mixed $value, DateTimeImmutable $fallback): string
    {
        if (is_string($value) && $value !== '') {
            try {
                return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
            } catch (Throwable) {
                // Некорректная строка — не повод терять проекцию.
            }
        }

        return $fallback->format(DateTimeInterface::ATOM);
    }

    /**
     * Положительное целое из payload; иное значение — null.
     */
    private function intValue(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Непустая строка из payload.
     */
    private function stringValue(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
