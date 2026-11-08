<?php

declare(strict_types=1);

namespace App\Actions\Tracking;

use App\Domain\Geography\Contracts\DistanceProvider;
use App\Domain\Geography\Coordinates;
use App\Domain\Geography\Exceptions\DistanceProviderUnavailable;
use App\Domain\Geography\Exceptions\InvalidCoordinates;
use App\Domain\Tracking\RemainingEta;
use App\Domain\Tracking\RemainingEtaCalculator;
use App\Http\Problem\ApiException;
use App\Models\Dispatch\Assignment;
use App\Models\Order\DeliveryOrder;
use App\Models\Tracking\AssignmentLastPosition;
use App\Models\Tracking\TrackingPoint;
use App\Models\User;
use App\Services\Pricing\PricingConfig;
use Illuminate\Pagination\CursorPaginator;

/**
 * Чтение трекинга заказа (FR-TRK-121…128, ЧТЗ 8.5).
 *
 * Операция проверяет доступ политикой заказа, выбирает назначение, чью ленту
 * показывать, и считает остаток пути. Зафиксированный прогноз заказа не
 * трогается: пересчитанный остаток отдаётся отдельным полем (FR-TRK-125).
 */
final readonly class ReadOrderTracking
{
    public function __construct(
        private DistanceProvider $distances,
        private PricingConfig $pricing,
        private RemainingEtaCalculator $etaCalculator,
    ) {}

    /**
     * @throws ApiException заказ чужой или недоступен роли
     */
    public function handle(User $user, DeliveryOrder $order, ?string $cursor, int $limit): OrderTrackingSnapshot
    {
        // Чужой заказ неотличим от несуществующего (FR-TRK-126); роли без
        // доступа к заказам отсекаются ещё в запросе (403).
        if (! $user->can('view', $order)) {
            throw ApiException::notFound();
        }

        $assignment = $this->assignment($order);

        return new OrderTrackingSnapshot(
            order: $order,
            remainingEta: $this->remainingEta($order, $assignment),
            lastPosition: $assignment?->lastPosition,
            courier: $assignment?->courier,
            points: $this->points($assignment, $cursor, $limit),
        );
    }

    /**
     * Назначение, чью ленту отдаём: активное, иначе последняя попытка.
     *
     * Активное назначение показывает текущее движение. Когда активного нет
     * (доставлено, не выполнено, отменено), владельцу нужен пройденный путь
     * последней попытки — иначе лента исчезала бы сразу после вручения.
     * Связи загружаются заранее: чтение не порождает N+1 (FR-TRK-121).
     */
    private function assignment(DeliveryOrder $order): ?Assignment
    {
        $query = Assignment::query()
            ->where('order_id', $order->getKey())
            ->with(['courier.courierProfile', 'lastPosition']);

        return (clone $query)
            ->active()
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->first()
            ?? $query->orderByDesc('assigned_at')->orderByDesc('id')->first();
    }

    /**
     * Лента геоточек назначения с курсорной пагинацией (FR-TRK-127).
     *
     * Порядок — время точки и идентификатор: он устойчив, поэтому между
     * страницами нет дублей и пропусков.
     *
     * @return CursorPaginator<int, TrackingPoint>
     */
    private function points(?Assignment $assignment, ?string $cursor, int $limit): CursorPaginator
    {
        // Назначения нет — лента пуста; идентификатора 0 в таблице не бывает.
        return TrackingPoint::query()
            ->where('assignment_id', $assignment?->getKey() ?? 0)
            ->orderBy('recorded_at')
            ->orderBy('id')
            ->cursorPaginate(perPage: $limit, cursorName: 'cursor', cursor: $cursor);
    }

    /**
     * Остаток пути или признак того, что он не считается.
     *
     * FR-TRK-123, FR-TRK-124: без позиции, при неактивном назначении, без
     * координат адреса и при недоступности провайдера расстояний остатка нет,
     * а источник — planned. Сбой провайдера клиенту не раскрывается.
     */
    private function remainingEta(DeliveryOrder $order, ?Assignment $assignment): RemainingEta
    {
        if (! $assignment instanceof Assignment || ! $assignment->isActive()) {
            return RemainingEta::planned();
        }

        $position = $assignment->lastPosition;

        if (! $position instanceof AssignmentLastPosition) {
            return RemainingEta::planned();
        }

        $dropoff = $this->dropoffCoordinates($order);

        if (! $dropoff instanceof Coordinates) {
            return RemainingEta::planned();
        }

        try {
            $from = new Coordinates($position->latitude, $position->longitude);
            $distance = $this->distances->between($from, $dropoff);
        } catch (InvalidCoordinates|DistanceProviderUnavailable) {
            return RemainingEta::planned();
        }

        return $this->etaCalculator->calculate(
            $distance,
            $this->pricing->transportParams($order->transport_type),
            $order->light_vehicle_kind,
            now()->toDateTimeImmutable(),
        );
    }

    /**
     * Координаты адреса доставки; null — их нет или они некорректны.
     */
    private function dropoffCoordinates(DeliveryOrder $order): ?Coordinates
    {
        $address = $order->dropoff_address;
        $latitude = $address['latitude'] ?? null;
        $longitude = $address['longitude'] ?? null;

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            return null;
        }

        try {
            return new Coordinates((float) $latitude, (float) $longitude);
        } catch (InvalidCoordinates) {
            return null;
        }
    }
}
