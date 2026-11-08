<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Notifications\NotificationStatus;
use App\Models\Concerns\ConvertsTimestampsToUtc;
use Database\Factories\NotificationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Журнал уведомлений сервиса (ЧТЗ 7.1, FR-NTF-261…267).
 *
 * Уведомление уникально по паре «событие — получатель — канал»: повторное
 * событие не создаёт вторую строку и не отправляется дважды. Допустимые
 * статусы защищены CHECK базы, переходы между ними задаёт домен.
 *
 * @property int $id
 * @property string $event_id
 * @property string|null $correlation_id
 * @property int|null $order_id
 * @property string|null $tracking_code
 * @property int $recipient_id
 * @property string $recipient_role
 * @property string $channel
 * @property string $event_type
 * @property string $template
 * @property NotificationStatus $status
 * @property int $attempts
 * @property string|null $last_error_code
 * @property int|null $fallback_from_notification_id
 * @property Carbon|null $deferred_until
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $sent_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Notification extends Model
{
    /** @use HasFactory<NotificationFactory> */
    use ConvertsTimestampsToUtc, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'correlation_id',
        'order_id',
        'tracking_code',
        'recipient_id',
        'recipient_role',
        'channel',
        'event_type',
        'template',
        'status',
        'attempts',
        'last_error_code',
        'fallback_from_notification_id',
        'deferred_until',
        'next_attempt_at',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'recipient_id' => 'integer',
            'attempts' => 'integer',
            'fallback_from_notification_id' => 'integer',
            'status' => NotificationStatus::class,
            'deferred_until' => 'datetime',
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Текущее состояние записи как значение домена.
     */
    public function statusValue(): NotificationStatus
    {
        return $this->status;
    }

    /**
     * Момент отправки хранится в UTC: трейт приводит его перед записью.
     *
     * @return list<string>
     */
    protected function utcAttributes(): array
    {
        return ['sent_at', 'deferred_until'];
    }
}
