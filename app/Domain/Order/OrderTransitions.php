<?php

declare(strict_types=1);

namespace App\Domain\Order;

use App\Domain\Order\Exceptions\OrderStatusTransitionNotAllowed;

/**
 * Таблица допустимых переходов статусов (ЧТЗ 5.2).
 *
 * Права роли здесь не проверяются: домен не знает о пользователях. Политика
 * и автор перехода — ответственность вызывающего слоя, который фиксирует
 * запись истории. Домен отвечает только за допустимость пары статусов.
 */
final class OrderTransitions
{
    /**
     * Разрешённые переходы: текущий статус → список целевых.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED = [
        OrderStatus::Created->value => [OrderStatus::Assigned->value, OrderStatus::Cancelled->value],
        OrderStatus::Assigned->value => [OrderStatus::Accepted->value, OrderStatus::Cancelled->value],
        OrderStatus::Accepted->value => [OrderStatus::PickedUp->value, OrderStatus::Cancelled->value],
        OrderStatus::PickedUp->value => [OrderStatus::Delivered->value, OrderStatus::Failed->value],
        OrderStatus::Delivered->value => [OrderStatus::Closed->value],
        // Возврат в работу после неудачной попытки (переназначение, этап M5).
        OrderStatus::Failed->value => [OrderStatus::Created->value],
        OrderStatus::Closed->value => [],
        OrderStatus::Cancelled->value => [],
    ];

    /**
     * Целевые статусы, доступные из текущего.
     *
     * @return list<OrderStatus>
     */
    public static function targets(OrderStatus $from): array
    {
        return array_map(
            static fn (string $status): OrderStatus => OrderStatus::from($status),
            self::ALLOWED[$from->value] ?? [],
        );
    }

    /**
     * Разрешён ли переход.
     */
    public static function isAllowed(OrderStatus $from, OrderStatus $to): bool
    {
        return in_array($to->value, self::ALLOWED[$from->value] ?? [], true);
    }

    /**
     * @throws OrderStatusTransitionNotAllowed переход не предусмотрен правилами
     */
    public static function assertAllowed(OrderStatus $from, OrderStatus $to): void
    {
        if (! self::isAllowed($from, $to)) {
            throw OrderStatusTransitionNotAllowed::make($from, $to);
        }
    }
}
