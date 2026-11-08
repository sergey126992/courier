<?php

declare(strict_types=1);

namespace App\Services\Events;

use App\Models\Order\DeliveryOrder;
use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Общий состав payload события заказа (FR-NTF-223, ЧТЗ 8.3).
 *
 * Сервису оповещений нужны только идентификаторы, код отслеживания, статус,
 * момент события и локаль: персональных данных (email, телефон, имя, адрес)
 * в событии нет. Идентификатор события равен message_id строки Outbox,
 * поэтому выдаётся здесь и передаётся в модель вместе с payload: повторная
 * публикация сохраняет event_id и дедупликация работает.
 */
final class OrderEventPayload
{
    /**
     * Идентификатор сообщения и payload события заказа.
     *
     * @param array<string, mixed> $fields поля конкретного перехода
     * @return array{message_id: string, payload: array<string, mixed>}
     */
    public static function build(
        DeliveryOrder $order,
        string $status,
        DateTimeInterface $occurredAt,
        array $fields = [],
    ): array {
        $eventId = (string) Str::uuid();

        return [
            'message_id' => $eventId,
            'payload' => [
                'event_id' => $eventId,
                'order_id' => $order->getKey(),
                'tracking_code' => $order->tracking_code,
                'sender_id' => (int) $order->sender_id,
                'status' => $status,
                'occurred_at' => $occurredAt->format(DateTimeInterface::ATOM),
                'locale' => app()->getLocale(),
                ...$fields,
            ],
        ];
    }
}
