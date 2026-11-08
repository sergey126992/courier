<?php

declare(strict_types=1);

namespace App\Models\Events;

use App\Models\Concerns\ConvertsTimestampsToUtc;
use Database\Factories\Events\ProcessedMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Отметка Inbox: событие обработано слушателем (FR-NTF-131…138).
 *
 * Отметка создаётся один раз в транзакции эффекта, поэтому времени изменения
 * нет. Уникальность пары «событие — обработчик» держит база: повторная
 * доставка не даёт второй отметки, а разные обработчики обрабатывают одно
 * событие независимо.
 *
 * Колонка processed_at — timestamptz, а часовой пояс приложения может быть не
 * UTC: перед записью момент приводится к UTC (ЧТЗ 7, п. 4).
 *
 * @property int $id
 * @property string $message_id
 * @property string $handler
 * @property Carbon $processed_at
 * @property Carbon $created_at
 */
class ProcessedMessage extends Model
{
    /** @use HasFactory<ProcessedMessageFactory> */
    use ConvertsTimestampsToUtc, HasFactory;

    /**
     * У отметки нет времени изменения: она создаётся один раз.
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'message_id',
        'handler',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'processed_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Момент обработки хранится в UTC: трейт приводит его перед записью.
     *
     * @return list<string>
     */
    protected function utcAttributes(): array
    {
        return ['processed_at'];
    }
}
