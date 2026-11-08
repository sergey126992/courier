<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ConvertsTimestampsToUtc;
use Database\Factories\InboxMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Обработанное сообщение потока (ЧТЗ 7.1, ЧТЗ 5.2).
 *
 * Повторная доставка того же message_id отсекается уникальным индексом
 * (message_id, consumer) (ЧТЗ 7, п. 3). Состояние «в работе» здесь не
 * хранится: это pending-запись consumer group.
 *
 * @property int $id
 * @property string $message_id
 * @property string $consumer
 * @property string $stream
 * @property string $status
 * @property string|null $error_code
 * @property Carbon|null $received_at
 * @property Carbon|null $processed_at
 * @property Carbon $created_at
 */
class InboxMessage extends Model
{
    /** @use HasFactory<InboxMessageFactory> */
    use ConvertsTimestampsToUtc, HasFactory;

    /**
     * @var string|null
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'message_id',
        'consumer',
        'stream',
        'status',
        'error_code',
        'received_at',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    protected function utcAttributes(): array
    {
        return ['received_at', 'processed_at'];
    }
}
