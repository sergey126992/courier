<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ConvertsTimestampsToUtc;
use Database\Factories\PushMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Сообщение фейкового push-провайдера (ЧТЗ 7.1, FR-NTF-251…258).
 *
 * Таблица только на добавление: сообщение не переписывается (ЧТЗ 7, п. 6).
 * Получатель хранится снимком, удаление уведомления лишь обнуляет ссылку.
 *
 * @property int $id
 * @property int|null $notification_id
 * @property int $recipient_id
 * @property string $template
 * @property string $body
 * @property Carbon|null $sent_at
 * @property Carbon $created_at
 */
class PushMessage extends Model
{
    /** @use HasFactory<PushMessageFactory> */
    use ConvertsTimestampsToUtc, HasFactory;

    /**
     * @var string|null
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'notification_id',
        'recipient_id',
        'template',
        'body',
        'sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notification_id' => 'integer',
            'recipient_id' => 'integer',
            'sent_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    protected function utcAttributes(): array
    {
        return ['sent_at'];
    }
}
