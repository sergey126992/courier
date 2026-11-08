<?php

declare(strict_types=1);

namespace App\Actions\Tracking;

use App\Http\Problem\ApiException;
use App\Models\Order\DeliveryOrder;
use App\Services\Tracking\PublicTrackingCache;

/**
 * Чтение публичного трекинга по коду (FR-TRK-134…138).
 *
 * Ответ собирается из белого списка полей: адрес, улица, дом, координаты,
 * имя, телефон, цена, номер заказа и сам код трекинга в ответ не попадают.
 * Операция только читает: событий и записей нет (FR-TRK-138, FR-TRK-143).
 * Готовый ответ кэшируется, поэтому повторное открытие в окне кэша не
 * обращается к базе (FR-TRK-137).
 */
final readonly class ReadPublicTracking
{
    public function __construct(private PublicTrackingCache $cache) {}

    /**
     * @throws ApiException кода трекинга не существует
     */
    public function handle(string $trackingCode): PublicTrackingSnapshot
    {
        $payload = $this->cache->remember(
            $trackingCode,
            fn (): array => $this->snapshot($trackingCode)->toArray(),
        );

        return PublicTrackingSnapshot::fromArray($payload);
    }

    /**
     * Публичный снимок заказа: статус, прогноз, район и время обновления.
     *
     * @throws ApiException код не существует; ответ не отличается от прочих 404
     */
    private function snapshot(string $trackingCode): PublicTrackingSnapshot
    {
        $order = DeliveryOrder::query()->where('tracking_code', $trackingCode)->first();

        if (! $order instanceof DeliveryOrder) {
            throw ApiException::notFound();
        }

        return new PublicTrackingSnapshot(
            status: $order->status->value,
            statusLabel: $order->status->label(),
            etaMinutes: $order->eta_minutes,
            // Зафиксированный при создании заказа прогноз не пересчитывается (FR-TRK-125).
            etaAt: $order->requested_at->copy()->addMinutes($order->eta_minutes)->toIso8601String(),
            district: $this->district($order),
            updatedAt: $order->updated_at->toIso8601String(),
        );
    }

    /**
     * Район адреса доставки без улицы и дома (FR-TRK-134).
     */
    private function district(DeliveryOrder $order): ?string
    {
        $district = $order->dropoff_address['district'] ?? null;

        return is_string($district) && trim($district) !== '' ? $district : null;
    }
}
