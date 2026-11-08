<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ConvertsTimestampsToUtc;
use Database\Factories\OutboxMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Исходящее обратное событие сервиса (ЧТЗ 7.1, FR-NTF-281…286).
 *
 * Идентификатор сообщения выдаёт база (DEFAULT gen_random_uuid()), поэтому
 * создатель события и публикатор не договариваются о нём. Публикатор берёт
 * только неопубликованные строки по частичному индексу.
 *
 * @property int $id
 * @property string $message_id
 * @property string $event_type
 * @property string $schema_version
 * @property string $aggregate_type
 * @property int $aggregate_id
 * @property array<string, mixed> $payload
 * @property string|null $correlation_id
 * @property Carbon|null $published_at
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon $available_at
 * @property Carbon $created_at
 */
class OutboxMessage extends Model
{
    /** @use HasFactory<OutboxMessageFactory> */
    use ConvertsTimestampsToUtc, HasFactory;

    /**
     * @var string|null
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'event_type',
        'schema_version',
        'aggregate_type',
        'aggregate_id',
        'payload',
        'correlation_id',
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
            'aggregate_id' => 'integer',
            'payload' => 'array',
            'attempts' => 'integer',
            'published_at' => 'datetime',
            'available_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    protected function utcAttributes(): array
    {
        return ['published_at', 'available_at'];
    }
}
