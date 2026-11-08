<?php

declare(strict_types=1);

namespace App\Models\Order;

use App\Models\Concerns\ConvertsTimestampsToUtc;
use App\Support\CorrelationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Исходящее событие: пишется в транзакции с заказом, публикуется на этапе M7.
 *
 * Идентификатор сообщения и correlation_id назначает приложение при записи:
 * event_id в payload события обязан совпасть с message_id (FR-NTF-223), а
 * correlation_id берётся из контекста запроса (FR-OPS-201). Время готовности
 * available_at заполняет приложение: колонка объявлена NOT NULL, а строки без
 * неё не попали бы в выборку публикатора.
 *
 * @property int $id
 * @property string $message_id
 * @property string|null $correlation_id
 * @property string $aggregate_type
 * @property int $aggregate_id
 * @property string $event_type
 * @property array<string, mixed> $payload
 * @property Carbon|null $published_at
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon $available_at
 * @property Carbon $created_at
 */
class OutboxMessage extends Model
{
    use ConvertsTimestampsToUtc;

    /**
     * У события нет времени изменения: только created_at.
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'message_id',
        'correlation_id',
        'aggregate_type',
        'aggregate_id',
        'event_type',
        'payload',
        'published_at',
        'attempts',
        'last_error',
        'available_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'published_at' => 'datetime',
            'attempts' => 'integer',
            'available_at' => 'datetime',
        ];
    }

    /**
     * Время готовности и отметка публикации хранятся в UTC: трейт приводит их перед записью.
     *
     * @return list<string>
     */
    protected function utcAttributes(): array
    {
        return ['available_at', 'published_at'];
    }

    /**
     * Значения по умолчанию при записи события.
     *
     * Готовность к публикации — момент записи. Идентификатор сообщения
     * назначается здесь, если место записи его не передало: значение нужно
     * до сохранения, потому что event_id в payload равен message_id
     * (FR-NTF-223). correlation_id берётся из контекста запроса, а вне HTTP
     * (команды, расписание) — новым UUID (FR-OPS-201, ЧТЗ 12.2 п. 8).
     */
    protected static function booted(): void
    {
        static::creating(function (OutboxMessage $message): void {
            if ($message->available_at === null) {
                $message->available_at = now();
            }

            if ($message->message_id === null) {
                $message->message_id = (string) Str::uuid();
            }

            if ($message->correlation_id === null) {
                $message->correlation_id = CorrelationContext::resolve();
            }
        });
    }
}
