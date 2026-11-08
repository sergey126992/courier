<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NotificationDedupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ключ дедупликации события (ЧТЗ 7.1, FR-NTF-261…267).
 *
 * Уникальность (event_id, recipient_id, channel) держит база (ЧТЗ 7, п. 3):
 * ключ захватывается в транзакции Inbox до создания уведомления.
 *
 * @property int $id
 * @property string $event_id
 * @property int $recipient_id
 * @property string $channel
 * @property int|null $notification_id
 * @property Carbon $created_at
 */
class NotificationDedup extends Model
{
    /** @use HasFactory<NotificationDedupFactory> */
    use HasFactory;

    /**
     * Таблица названа в единственном числе: ключ дедупликации — не сущность.
     *
     * @var string
     */
    protected $table = 'notification_dedup';

    /**
     * Строка создаётся один раз и не меняется.
     *
     * @var string|null
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_id',
        'recipient_id',
        'channel',
        'notification_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipient_id' => 'integer',
            'notification_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
