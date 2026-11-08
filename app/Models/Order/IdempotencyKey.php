<?php

declare(strict_types=1);

namespace App\Models\Order;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Ключ идемпотентности создания заказа (FR-ORD-108…112).
 *
 * Уникален в пределах пользователя; хранит отпечаток тела и сохранённый ответ,
 * поэтому повтор с тем же телом возвращает тот же результат.
 *
 * @property int $id
 * @property int $user_id
 * @property string $key
 * @property string $request_hash
 * @property string $status
 * @property int|null $response_code
 * @property array<string, mixed>|null $response_body
 * @property Carbon $expires_at
 */
class IdempotencyKey extends Model
{
    /**
     * Состояние «запрос выполняется»: параллельный повтор получает конфликт.
     */
    public const STATUS_IN_PROGRESS = 'in_progress';

    /**
     * Состояние «ответ сохранён»: повтор получает сохранённый результат.
     */
    public const STATUS_COMPLETED = 'completed';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'key',
        'request_hash',
        'status',
        'response_code',
        'response_body',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_code' => 'integer',
            'response_body' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
