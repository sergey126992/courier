<?php

declare(strict_types=1);

namespace App\Models\Tracking;

use App\Models\User;
use Database\Factories\Tracking\CourierLastPositionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Последняя позиция курьера (FR-TRK-111…116).
 *
 * Одна строка на курьера: обновляется геоточками назначения и heartbeat,
 * поэтому позиция известна и без активного назначения.
 *
 * @property int $id
 * @property int $courier_id
 * @property float $latitude
 * @property float $longitude
 * @property Carbon $recorded_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CourierLastPosition extends Model
{
    /** @use HasFactory<CourierLastPositionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'courier_id',
        'latitude',
        'longitude',
        'recorded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'recorded_at' => 'datetime',
        ];
    }

    /**
     * Курьер, позиция которого хранится.
     *
     * @return BelongsTo<User, $this>
     */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_id');
    }
}
