<?php

declare(strict_types=1);

namespace App\Domain\Order;

/**
 * Статус заказа на доставку (ЧТЗ 5.1).
 *
 * Значения совпадают с ограничением таблицы и с кодами API: статус меняется
 * только доменными методами агрегата, напрямую в базе — никогда.
 */
enum OrderStatus: string
{
    case Created = 'created';
    case Assigned = 'assigned';
    case Accepted = 'accepted';
    case PickedUp = 'picked_up';
    case Delivered = 'delivered';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';

    /**
     * Человекочитаемое название статуса.
     */
    public function label(): string
    {
        return match ($this) {
            self::Created => 'Создан',
            self::Assigned => 'Назначен',
            self::Accepted => 'Принят',
            self::PickedUp => 'Забран',
            self::Delivered => 'Вручён',
            self::Closed => 'Закрыт',
            self::Cancelled => 'Отменён',
            self::Failed => 'Не выполнен',
        };
    }

    /**
     * Терминальный статус: из него переходов нет.
     *
     * «Не выполнен» терминальным не считается: заказ возвращается в «создан»
     * для повторного назначения.
     */
    public function isTerminal(): bool
    {
        return $this === self::Closed || $this === self::Cancelled;
    }

    /**
     * Заказ ещё в работе (не закрыт и не отменён).
     */
    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }
}
