<?php

declare(strict_types=1);

namespace App\Actions\Tracking;

use App\Domain\Tracking\RemainingEta;
use App\Models\Order\DeliveryOrder;
use App\Models\Tracking\AssignmentLastPosition;
use App\Models\Tracking\TrackingPoint;
use App\Models\User;
use Illuminate\Pagination\CursorPaginator;

/**
 * Готовые к выдаче данные трекинга заказа (ЧТЗ 8.5).
 *
 * Снимок собирает операция чтения: связи уже загружены, остаток ETA посчитан
 * или помечен как planned. Слой ответа только раскладывает данные по полям.
 */
final readonly class OrderTrackingSnapshot
{
    /**
     * @param CursorPaginator<int, TrackingPoint> $points лента активного назначения
     */
    public function __construct(
        public DeliveryOrder $order,
        public RemainingEta $remainingEta,
        public ?AssignmentLastPosition $lastPosition,
        public ?User $courier,
        public CursorPaginator $points,
    ) {}
}
