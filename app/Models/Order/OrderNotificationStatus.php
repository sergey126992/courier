<?php

declare(strict_types=1);

namespace App\Models\Order;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Проекция статуса доставки уведомления по каналу (ЧТЗ 7.2, FR-NTF-284).
 *
 * Одна строка на уведомление сервиса: статус приходит обратным событием
 * и применяется идемпотентно. Модель только читается карточкой заказа;
 * запись выполняет проектор из потребителя обратных событий.
 *
 * @property int $id
 * @property int $order_id
 * @property int $notification_id
 * @property string $channel
 * @property string $status
 * @property int $attempt
 * @property string|null $error_code
 * @property string $source_event_id
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class OrderNotificationStatus extends Model
{
    /**
     * @var string
     */
    protected $table = 'order_notification_status';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'notification_id',
        'channel',
        'status',
        'attempt',
        'error_code',
        'source_event_id',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'notification_id' => 'integer',
            'attempt' => 'integer',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
