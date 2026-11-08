<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ConvertsTimestampsToUtc;
use Database\Factories\ChannelBindingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Привязка канала к получателю (ЧТЗ 7.1, FR-NTF-231…236).
 *
 * Активная привязка одна на пару «пользователь — канал»: частичный уникальный
 * индекс по строкам без revoked_at. Адрес (email, push, telegram) — это
 * персональные данные: в логах и ответах API он не выводится
 * (FR-OPS-202, FR-OPS-210).
 *
 * @property int $id
 * @property int $user_id
 * @property string $channel
 * @property string $address
 * @property Carbon|null $verified_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ChannelBinding extends Model
{
    /** @use HasFactory<ChannelBindingFactory> */
    use ConvertsTimestampsToUtc, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'channel',
        'address',
        'verified_at',
        'revoked_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'verified_at' => 'datetime',
            'revoked_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * @return list<string>
     */
    protected function utcAttributes(): array
    {
        return ['verified_at', 'revoked_at'];
    }
}
