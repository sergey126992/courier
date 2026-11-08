<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ConvertsTimestampsToUtc;
use Database\Factories\TelegramLinkTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Одноразовый токен привязки Telegram (ЧТЗ 7.1, FR-NTF-411…421).
 *
 * Строка неизменяема, кроме отметки `used_at`: повторное подтверждение
 * отклоняется, а открытого значения токена в базе нет (FR-NTF-413).
 *
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon $created_at
 */
class TelegramLinkToken extends Model
{
    /** @use HasFactory<TelegramLinkTokenFactory> */
    use ConvertsTimestampsToUtc, HasFactory;

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
        'user_id',
        'token_hash',
        'expires_at',
        'used_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    protected function utcAttributes(): array
    {
        return ['expires_at', 'used_at'];
    }
}
