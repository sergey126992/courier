<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Order\OutboxMessage;

/**
 * Строка Outbox для тестов публикатора: payload удовлетворяет реестру типов.
 */
final class Outbox
{
    /**
     * @param array<string, mixed> $overrides
     */
    public static function create(array $overrides = []): OutboxMessage
    {
        return OutboxMessage::create(array_merge([
            'aggregate_type' => 'order',
            'aggregate_id' => 1,
            'event_type' => 'order.created',
            'payload' => self::payload([
                'pickup_district' => 'Северный',
                'transport' => 'light',
                'price_amount' => 19900,
                'price_currency' => 'RUB',
            ]),
        ], $overrides));
    }

    /**
     * Событие отмены заказа со всеми обязательными полями payload.
     *
     * @param array<string, mixed> $overrides
     */
    public static function cancelled(array $overrides = []): OutboxMessage
    {
        return self::create(array_merge([
            'aggregate_id' => 2,
            'event_type' => 'order.cancelled',
            'payload' => self::payload([
                'order_id' => 2,
                'tracking_code' => 'TRK-2026-000002',
                'status' => 'cancelled',
                'cancelled_by' => 1,
                'cancelled_by_role' => 'sender',
                'cancelled_at' => '2026-05-01T09:00:00+00:00',
                'reason_code' => null,
            ]),
        ], $overrides));
    }

    /**
     * Общий состав payload события заказа (FR-NTF-223, ЧТЗ 8.3).
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function payload(array $fields = []): array
    {
        return array_merge([
            'event_id' => '0c1e5f6a-1b2c-4d3e-8f90-1a2b3c4d5e6f',
            'order_id' => 1,
            'tracking_code' => 'TRK-2026-000001',
            'sender_id' => 1,
            'status' => 'created',
            'occurred_at' => '2026-05-01T09:00:00+00:00',
            'locale' => 'ru',
        ], $fields);
    }
}
