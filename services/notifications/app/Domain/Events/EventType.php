<?php

declare(strict_types=1);

namespace App\Domain\Events;

/**
 * Типы событий, которые принимает сервис оповещений (FR-NTF-223, FR-NTF-224).
 *
 * Набор повторяет реестр M7 монолита: имена стабильны, нижний регистр,
 * dot.case, без переименований. Неизвестный тип не роняет потребитель:
 * сообщение помечается invalid, подтверждается и поток не блокирует.
 */
enum EventType: string
{
    case OrderCreated = 'order.created';
    case OrderAssigned = 'order.assigned';
    case OrderPickedUp = 'order.picked_up';
    case OrderDelivered = 'order.delivered';
    case OrderFailed = 'order.failed';
    case OrderCancelled = 'order.cancelled';
    case CourierShiftStarted = 'courier.shift.started';
    case CourierShiftEnded = 'courier.shift.ended';
    case UserContactChanged = 'user.contact_changed';
}
