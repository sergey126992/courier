<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Identity\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Запись журнала назначения ролей: только добавление, без правки и удаления.
 *
 * @property int $user_id
 * @property Role $previous_role
 * @property Role $new_role
 * @property int|null $changed_by
 */
class RoleChange extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'previous_role',
        'new_role',
        'changed_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_role' => Role::class,
            'new_role' => Role::class,
        ];
    }

    /**
     * Пользователь, чья роль изменена.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Автор изменения роли.
     *
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
