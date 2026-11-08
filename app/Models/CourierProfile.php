<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\TransportType;
use Database\Factories\CourierProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Профиль курьера: один на пользователя.
 *
 * @property int $user_id
 * @property TransportType $transport_type
 * @property LightVehicleKind|null $light_vehicle_kind
 * @property bool $is_active
 */
class CourierProfile extends Model
{
    /** @use HasFactory<CourierProfileFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'transport_type',
        'light_vehicle_kind',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'transport_type' => TransportType::class,
            'light_vehicle_kind' => LightVehicleKind::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * Владелец профиля.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
