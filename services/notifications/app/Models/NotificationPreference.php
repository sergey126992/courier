<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\NotificationPreferenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Предпочтения получателя по типу события (ЧТЗ 7.1, FR-NTF-451…462).
 *
 * Строка — сохранённая настройка пользователя; отсутствие строки означает
 * дефолт матрицы M11 (FR-NTF-452, FR-NTF-459). `channels` и `quiet_hours`
 * хранятся в jsonb и приходят массивами; FORM проверяет CHECK базы, а состав
 * каналов и окно — доменный валидатор (ЧТЗ 12.1). `critical_override`
 * отмечает строку, где сервис принудительно оставил email критичного
 * события (FR-NTF-455). Персональных данных в строке нет.
 *
 * @property int $id
 * @property int $user_id
 * @property string $event_type
 * @property list<string> $channels
 * @property array<string, mixed>|null $quiet_hours
 * @property string $locale
 * @property bool $critical_override
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class NotificationPreference extends Model
{
    /** @use HasFactory<NotificationPreferenceFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'event_type',
        'channels',
        'quiet_hours',
        'locale',
        'critical_override',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'channels' => 'array',
            'quiet_hours' => 'array',
            'locale' => 'string',
            'critical_override' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
