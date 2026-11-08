<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Identity\Role;
use App\Mail\VerifyEmailMail;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property Role $role
 * @property-read SenderProfile|null $senderProfile
 * @property-read CourierProfile|null $courierProfile
 */
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, MustVerifyEmailTrait, Notifiable;

    /**
     * Значения по умолчанию на уровне модели.
     *
     * Роль дублирует DEFAULT базы, чтобы новый экземпляр без перезагрузки
     * совпадал с сохранённой записью и каст сразу отдавал перечисление.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role' => Role::Sender->value,
    ];

    /**
     * Поля, доступные массовому присвоению.
     *
     * Роли здесь нет намеренно: роль назначается отдельно и только администратором.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }

    /**
     * Письмо подтверждения: уходит через очередь и строится по русскому шаблону.
     */
    public function sendEmailVerificationNotification(): void
    {
        Mail::to($this)->queue(new VerifyEmailMail($this));
    }

    /**
     * Профиль отправителя (есть только у роли «отправитель»).
     *
     * @return HasOne<SenderProfile, $this>
     */
    public function senderProfile(): HasOne
    {
        return $this->hasOne(SenderProfile::class);
    }

    /**
     * Профиль курьера (есть только у роли «курьер»).
     *
     * @return HasOne<CourierProfile, $this>
     */
    public function courierProfile(): HasOne
    {
        return $this->hasOne(CourierProfile::class);
    }
}
