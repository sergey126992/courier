<?php

declare(strict_types=1);

namespace App\Domain\Dispatch;

/**
 * Статус попытки назначения курьера (ЧТЗ 5.2).
 *
 * Значения совпадают с ограничением таблицы и кодами API. Активные статусы
 * (назначено, принято, забрано) — те, при которых попытка ещё в работе; из них
 * нет перехода в «назначено» повторно, а у курьера и заказа одновременно может
 * быть только одна такая строка.
 */
enum AssignmentStatus: string
{
    case Assigned = 'assigned';
    case Accepted = 'accepted';
    case PickedUp = 'picked_up';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /**
     * Человекочитаемое название статуса.
     */
    public function label(): string
    {
        return match ($this) {
            self::Assigned => 'Назначено',
            self::Accepted => 'Принято',
            self::PickedUp => 'Забрано',
            self::Delivered => 'Вручено',
            self::Failed => 'Не выполнено',
            self::Cancelled => 'Отменено',
        };
    }

    /**
     * Попытка ещё в работе: точка и действия курьера допустимы.
     */
    public function isActive(): bool
    {
        return in_array($this, self::active(), true);
    }

    /**
     * Терминальный статус: из него переходов нет, продолжение — новая попытка.
     */
    public function isTerminal(): bool
    {
        return ! $this->isActive();
    }

    /**
     * Активные статусы: назначено, принято, забрано (FR-DSP-134).
     *
     * @return list<self>
     */
    public static function active(): array
    {
        return [self::Assigned, self::Accepted, self::PickedUp];
    }

    /**
     * Значения активных статусов для запросов и частичных индексов.
     *
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::active());
    }
}
