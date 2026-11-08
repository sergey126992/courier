<?php

declare(strict_types=1);

namespace App\Models\Order;

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use App\Domain\Order\OrderStatus;
use App\Domain\Order\StatusTransition;
use App\Models\Geography\ServiceZone;
use App\Models\User;
use Database\Factories\Order\DeliveryOrderFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Заказ на доставку: зафиксированные цена, прогноз и расстояние.
 *
 * Статуса нет в $fillable: он меняется только доменными методами (FR-ORD-127).
 * Адреса, посылка и разбивка хранятся структурами в том же составе, что
 * доменные объекты-значения.
 *
 * @property int $id
 * @property string $number
 * @property string $tracking_code
 * @property int $sender_id
 * @property int $zone_id
 * @property OrderStatus $status
 * @property TransportType $transport_type
 * @property LightVehicleKind|null $light_vehicle_kind
 * @property array<string, mixed> $pickup_address
 * @property array<string, mixed> $dropoff_address
 * @property array<string, mixed> $parcel
 * @property float $distance_km
 * @property int $price_amount
 * @property string $price_currency
 * @property array<int, array<string, mixed>> $price_breakdown
 * @property int $eta_minutes
 * @property Carbon $requested_at
 * @property Carbon|null $cancelled_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DeliveryOrder extends Model
{
    /** @use HasFactory<DeliveryOrderFactory> */
    use HasFactory;

    /**
     * Поля, доступные массовому присвоению.
     *
     * Статуса здесь нет намеренно: переход выполняет домен, а не запрос.
     *
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'tracking_code',
        'sender_id',
        'zone_id',
        'transport_type',
        'light_vehicle_kind',
        'pickup_address',
        'dropoff_address',
        'parcel',
        'distance_km',
        'price_amount',
        'price_currency',
        'price_breakdown',
        'eta_minutes',
        'requested_at',
        'cancelled_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'transport_type' => TransportType::class,
            'light_vehicle_kind' => LightVehicleKind::class,
            'pickup_address' => 'array',
            'dropoff_address' => 'array',
            'parcel' => 'array',
            'price_breakdown' => 'array',
            'distance_km' => 'float',
            'price_amount' => 'integer',
            'eta_minutes' => 'integer',
            'requested_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Записывает результат доменного перехода в строку заказа.
     *
     * Единственное место, где меняется статус: значение приходит из
     * StatusTransition, то есть уже проверено правилами домена (FR-ORD-127).
     */
    public function applyTransition(StatusTransition $transition): void
    {
        $this->status = $transition->to();

        if ($transition->to() === OrderStatus::Cancelled) {
            $this->cancelled_at = now();
        }

        $this->save();
    }

    /**
     * Заказы, доступные пользователю: диспетчер видит все, остальные — свои.
     *
     * @param Builder<DeliveryOrder> $query
     * @return Builder<DeliveryOrder>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->role->canDispatch()
            ? $query
            : $query->where('sender_id', $user->getKey());
    }

    /**
     * Отправитель заказа.
     *
     * @return BelongsTo<User, $this>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * Зона обслуживания, определённая по адресу забора.
     *
     * @return BelongsTo<ServiceZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class, 'zone_id');
    }

    /**
     * История статусов заказа в порядке добавления.
     *
     * @return HasMany<OrderStatusHistory, $this>
     */
    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class, 'order_id')->orderBy('id');
    }
}
