<?php

declare(strict_types=1);

namespace Database\Factories\Order;

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Order\OrderStatus;
use App\Domain\Order\TrackingCode;
use App\Models\Geography\ServiceZone;
use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryOrder>
 */
class DeliveryOrderFactory extends Factory
{
    protected $model = DeliveryOrder::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $year = (int) now()->format('Y');

        return [
            'number' => sprintf('CRG-%d-%06d', $year, fake()->unique()->numberBetween(1, 999999)),
            // Код трекинга — тот же генератор, что в домене: формат не дублируется.
            'tracking_code' => TrackingCode::generate()->value(),
            'sender_id' => User::factory()->sender(),
            'zone_id' => ServiceZone::factory(),
            'status' => OrderStatus::Created,
            'transport_type' => TransportType::Light,
            'light_vehicle_kind' => LightVehicleKind::Bicycle,
            'pickup_address' => [
                'district' => 'Тверской',
                'street' => 'Тверская',
                'house' => '7',
                'latitude' => 55.755826,
                'longitude' => 37.6173,
            ],
            'dropoff_address' => [
                'district' => 'Тверской',
                'street' => 'Тверская',
                'house' => '9',
                'latitude' => 55.768279,
                'longitude' => 37.6173,
            ],
            'parcel' => [
                'weight_grams' => 1500,
                'length_cm' => 30,
                'width_cm' => 20,
                'height_cm' => 10,
            ],
            'distance_km' => 1.8,
            'price_amount' => 19400,
            'price_currency' => 'RUB',
            // Сумма строк равна цене: расхождение считается дефектом (PriceQuote).
            'price_breakdown' => [
                ['code' => 'base', 'label' => 'Базовая ставка', 'amount' => 14900],
                ['code' => 'distance', 'label' => '1.8 км', 'amount' => 4500],
                ['code' => 'surge', 'label' => 'Надбавка за час пик', 'amount' => 0],
            ],
            'eta_minutes' => 15,
            'requested_at' => now(),
        ];
    }

    /**
     * Заказ в указанном статусе (для проверки чтения и переходов).
     */
    public function withStatus(OrderStatus $status): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => $status,
            'cancelled_at' => $status === OrderStatus::Cancelled ? now() : null,
        ]);
    }
}
