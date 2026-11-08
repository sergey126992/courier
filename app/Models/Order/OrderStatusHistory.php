<?php

declare(strict_types=1);

namespace App\Models\Order;

use App\Domain\Order\Exceptions\StatusHistoryIsImmutable;
use App\Domain\Order\OrderStatus;
use App\Models\User;
use Database\Factories\Order\OrderStatusHistoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись истории статусов: только добавление (FR-ORD-126).
 *
 * Защита выбрана на уровне модели, а не событиями: переопределение save/delete
 * отклоняет попытку даже из кода, который не вызывает события вручную, и не
 * требует регистрации слушателей. Массовое удаление через Query Builder
 * остаётся возможным только в служебных задачах и в этом срезе не используется.
 *
 * @property int $id
 * @property int $order_id
 * @property OrderStatus|null $from_status
 * @property OrderStatus $to_status
 * @property int|null $changed_by
 * @property string|null $reason
 */
class OrderStatusHistory extends Model
{
    /** @use HasFactory<OrderStatusHistoryFactory> */
    use HasFactory;

    /**
     * Таблица истории статусов (имя в единственном числе по разделу 7 ЧТЗ).
     *
     * @var string
     */
    protected $table = 'order_status_history';

    /**
     * У записи истории нет времени изменения: только created_at.
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'from_status',
        'to_status',
        'changed_by',
        'reason',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => OrderStatus::class,
            'to_status' => OrderStatus::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * Заказ, к которому относится запись.
     *
     * @return BelongsTo<DeliveryOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class, 'order_id');
    }

    /**
     * Автор перехода; null — действие системы или первая запись без автора.
     *
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * @throws StatusHistoryIsImmutable запись уже сохранена
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw StatusHistoryIsImmutable::forUpdate($this->getKey());
        }

        return parent::save($options);
    }

    /**
     * @throws StatusHistoryIsImmutable удаление запрещено
     */
    public function delete(): ?bool
    {
        throw StatusHistoryIsImmutable::forDelete($this->getKey());
    }
}
