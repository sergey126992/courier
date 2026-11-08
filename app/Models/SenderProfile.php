<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SenderProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Профиль отправителя: один на пользователя.
 *
 * @property int $user_id
 * @property string|null $phone
 * @property string|null $company
 * @property array<string, mixed>|null $default_address
 */
class SenderProfile extends Model
{
    /** @use HasFactory<SenderProfileFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'phone',
        'company',
        'default_address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'default_address' => 'array',
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
