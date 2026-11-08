<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RecipientContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Контакт получателя из события user.contact_changed (ЧТЗ 7.1, FR-NTF-231…236).
 *
 * Email хранится только здесь и в channel_bindings (ЧТЗ 7, п. 7): в журнале,
 * логах и ответах API его нет. Первичный ключ — идентификатор пользователя
 * монолита; внешнего ключа на users нет (ЧТЗ 7, п. 2).
 *
 * @property int $user_id
 * @property string $email
 * @property string $locale
 * @property string|null $source_event_id
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class RecipientContact extends Model
{
    /** @use HasFactory<RecipientContactFactory> */
    use HasFactory;

    /**
     * @var string
     */
    protected $primaryKey = 'user_id';

    /**
     * Идентификатор приходит из события, а не выдаётся базой.
     *
     * @var bool
     */
    public $incrementing = false;

    /**
     * @var string
     */
    protected $keyType = 'int';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'email',
        'locale',
        'source_event_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
