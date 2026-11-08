<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Notifications\DlqState;
use App\Models\Concerns\ConvertsTimestampsToUtc;
use Database\Factories\DlqEntryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Запись очереди необработанных по каналу (ЧТЗ 7.1, FR-NTF-501…509).
 *
 * Хранит снимок уведомления без тела сообщения и персональных данных:
 * причина остаётся кодом и классом ошибки. Одна строка журнала даёт не более
 * одной записи на канал (уникальный индекс `notification_id` + `channel`).
 *
 * Запись переживает очистку журнала: внешнего ключа нет, поэтому повтор по
 * удалённой строке `notifications` возвращает «не найдено». Состояния
 * защищены CHECK базы, допустимые переходы задаёт домен `DlqState`.
 *
 * @property int $id
 * @property int $notification_id
 * @property string $channel
 * @property DlqState $state
 * @property string $event_id
 * @property string $event_type
 * @property int|null $order_id
 * @property int $recipient_id
 * @property string $recipient_role
 * @property string|null $error_class
 * @property string|null $error_code
 * @property int $attempts
 * @property string|null $correlation_id
 * @property Carbon $failed_at
 * @property int $replay_count
 * @property Carbon|null $last_replayed_at
 * @property Carbon|null $resolved_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DlqEntry extends Model
{
    /** @use HasFactory<DlqEntryFactory> */
    use ConvertsTimestampsToUtc, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'notification_id',
        'channel',
        'state',
        'event_id',
        'event_type',
        'order_id',
        'recipient_id',
        'recipient_role',
        'error_class',
        'error_code',
        'attempts',
        'correlation_id',
        'failed_at',
        'replay_count',
        'last_replayed_at',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notification_id' => 'integer',
            'order_id' => 'integer',
            'recipient_id' => 'integer',
            'attempts' => 'integer',
            'replay_count' => 'integer',
            'state' => DlqState::class,
            'failed_at' => 'datetime',
            'last_replayed_at' => 'datetime',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Моменты времени хранятся в UTC: трейт приводит их перед записью.
     *
     * @return list<string>
     */
    protected function utcAttributes(): array
    {
        return ['failed_at', 'last_replayed_at', 'resolved_at'];
    }
}
