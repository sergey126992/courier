<?php

declare(strict_types=1);

namespace App\Domain\Events;

/**
 * Владелец агрегата доменного события (FR-NTF-111).
 *
 * Значение совпадает с aggregate_type в outbox_messages: публикатор и
 * потребители разбирают событие по этому полю, а не по имени типа.
 */
enum AggregateType: string
{
    case Order = 'order';
    case CourierShift = 'courier_shift';
    case User = 'user';
    /**
     * Обратное событие сервиса оповещений (FR-NTF-281).
     */
    case Notification = 'notification';
}
