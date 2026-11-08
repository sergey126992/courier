<?php

declare(strict_types=1);

namespace App\Models\Dispatch;

use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Dispatch\AssignmentStrategy;
use App\Domain\Dispatch\AssignmentTransitions;
use App\Domain\Dispatch\Exceptions\AssignmentTransitionNotAllowed;
use App\Domain\Dispatch\FailureReason;
use App\Models\Concerns\ConvertsTimestampsToUtc;
use App\Models\Order\DeliveryOrder;
use App\Models\Tracking\AssignmentLastPosition;
use App\Models\Tracking\TrackingPoint;
use App\Models\User;
use Database\Factories\Dispatch\AssignmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Попытка назначения курьера на заказ (FR-DSP-131…139).
 *
 * Одна строка — одна попытка: история не перезаписывается. Статуса нет в
 * $fillable: его меняют операции домена, а не массовое присвоение из запроса.
 * Двойное активное назначение у заказа и у курьера отсекают частичные
 * уникальные индексы.
 *
 * @property int $id
 * @property int $order_id
 * @property int $courier_id
 * @property AssignmentStatus $status
 * @property AssignmentStrategy $strategy
 * @property float|null $distance_to_pickup_km
 * @property Carbon $assigned_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $picked_up_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $failed_at
 * @property Carbon|null $cancelled_at
 * @property FailureReason|null $failure_reason
 * @property string|null $failure_comment
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Assignment extends Model
{
    /** @use HasFactory<AssignmentFactory> */
    use ConvertsTimestampsToUtc, HasFactory;

    /**
     * Статуса здесь нет намеренно: переход выполняет домен, а не запрос.
     *
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'courier_id',
        'strategy',
        'distance_to_pickup_km',
        'assigned_at',
        'accepted_at',
        'picked_up_at',
        'delivered_at',
        'failed_at',
        'cancelled_at',
        'failure_reason',
        'failure_comment',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AssignmentStatus::class,
            'strategy' => AssignmentStrategy::class,
            'failure_reason' => FailureReason::class,
            'distance_to_pickup_km' => 'float',
            'assigned_at' => 'datetime',
            'accepted_at' => 'datetime',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * Моменты этапов хранятся в UTC: трейт приводит их перед записью.
     *
     * @return list<string>
     */
    protected function utcAttributes(): array
    {
        return [
            'assigned_at',
            'accepted_at',
            'picked_up_at',
            'delivered_at',
            'failed_at',
            'cancelled_at',
        ];
    }

    /**
     * Записывает переход статуса и метку этапа.
     *
     * Единственное место, где меняется статус: пара проверяется таблицей
     * переходов домена (ЧТЗ 5.2). У новой строки текущего статуса нет —
     * начальному статусу «назначено» соответствует переход «— → назначено».
     *
     * @throws AssignmentTransitionNotAllowed переход запрещён
     */
    public function applyTransition(AssignmentStatus $status): void
    {
        $current = $this->getAttribute('status');

        if ($current instanceof AssignmentStatus) {
            AssignmentTransitions::assertAllowed($current, $status);
        }

        $this->status = $status;
        $this->setAttribute(self::stageAttribute($status), now());
        $this->save();
    }

    /**
     * Попытка ещё в работе (назначено, принято, забрано).
     */
    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Активные назначения: по ним работают фильтры занятости курьера.
     *
     * @param Builder<Assignment> $query
     * @return Builder<Assignment>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', AssignmentStatus::activeValues());
    }

    /**
     * Заказ, на который сделана попытка.
     *
     * @return BelongsTo<DeliveryOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class, 'order_id');
    }

    /**
     * Курьер попытки.
     *
     * @return BelongsTo<User, $this>
     */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_id');
    }

    /**
     * Геоточки попытки в устойчивом порядке: время, затем идентификатор.
     *
     * @return HasMany<TrackingPoint, $this>
     */
    public function points(): HasMany
    {
        return $this->hasMany(TrackingPoint::class, 'assignment_id')
            ->orderBy('recorded_at')
            ->orderBy('id');
    }

    /**
     * Последняя позиция попытки: ровно одна строка (FR-TRK-111).
     *
     * Связь нужна чтению трекинга, чтобы последняя позиция загружалась
     * заранее и ответ не порождал N+1.
     *
     * @return HasOne<AssignmentLastPosition, $this>
     */
    public function lastPosition(): HasOne
    {
        return $this->hasOne(AssignmentLastPosition::class, 'assignment_id');
    }

    /**
     * Колонка, в которую ставится метка пройденного этапа.
     */
    private static function stageAttribute(AssignmentStatus $status): string
    {
        return match ($status) {
            AssignmentStatus::Assigned => 'assigned_at',
            AssignmentStatus::Accepted => 'accepted_at',
            AssignmentStatus::PickedUp => 'picked_up_at',
            AssignmentStatus::Delivered => 'delivered_at',
            AssignmentStatus::Failed => 'failed_at',
            AssignmentStatus::Cancelled => 'cancelled_at',
        };
    }
}
