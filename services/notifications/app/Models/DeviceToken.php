<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ConvertsTimestampsToUtc;
use Database\Factories\DeviceTokenFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Токен устройства для реального push (ЧТЗ 7.1, FR-NTF-525…530).
 *
 * Токен — секрет: он хранится целиком только для отправки и не выводится
 * ни в API, ни в журнал. Для логов есть `fingerprint` — хэш токена
 * (FR-NTF-529). Активный токен — строка с пустым `revoked_at`; отозванные
 * очищаются по сроку хранения (FR-NTF-530).
 *
 * @property int $id
 * @property int $user_id
 * @property string $token
 * @property string $fingerprint
 * @property string $platform
 * @property string|null $locale
 * @property string|null $app_version
 * @property Carbon $last_seen_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class DeviceToken extends Model
{
    /** @use HasFactory<DeviceTokenFactory> */
    use ConvertsTimestampsToUtc, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'token',
        'fingerprint',
        'platform',
        'locale',
        'app_version',
        'last_seen_at',
        'revoked_at',
    ];

    /**
     * Отпечаток токена для логов: необратимый, полного токена не раскрывает.
     */
    public static function fingerprint(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Только активные токены: отозванные не участвуют в адресации.
     *
     * @param Builder<DeviceToken> $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'last_seen_at' => 'datetime',
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
        return ['last_seen_at', 'revoked_at'];
    }
}
