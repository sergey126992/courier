<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NotificationAttemptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Попытка отправки уведомления (ЧТЗ 7.1, FR-NTF-271…278).
 *
 * Таблица только на добавление: строка фиксируется один раз и не
 * переписывается, поэтому времени изменения у модели нет (ЧТЗ 7, п. 6).
 *
 * @property int $id
 * @property int $notification_id
 * @property int $attempt
 * @property string $status
 * @property string|null $error_class
 * @property string|null $error_code
 * @property string|null $error_message
 * @property string|null $provider_code
 * @property Carbon $created_at
 */
class NotificationAttempt extends Model
{
    /** @use HasFactory<NotificationAttemptFactory> */
    use HasFactory;

    /**
     * Времени изменения у попытки нет: строка только добавляется.
     *
     * @var string|null
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'notification_id',
        'attempt',
        'status',
        'error_class',
        'error_code',
        'error_message',
        'provider_code',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notification_id' => 'integer',
            'attempt' => 'integer',
            'created_at' => 'datetime',
        ];
    }
}
