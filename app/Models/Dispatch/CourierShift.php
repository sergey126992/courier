<?php

declare(strict_types=1);

namespace App\Models\Dispatch;

use App\Models\Concerns\ConvertsTimestampsToUtc;
use App\Models\Geography\ServiceZone;
use App\Models\User;
use Database\Factories\Dispatch\CourierShiftFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Смена курьера (FR-DSP-101…106).
 *
 * Отдельного статуса смены нет: смена открыта, пока не заполнено ended_at,
 * поэтому состояние выводится из данных и не может разойтись с ними.
 * Пересечение смен запрещено ограничением базы courier_shifts_period_excl.
 *
 * Колонки смены — timestamptz, а часовой пояс приложения может быть не UTC.
 * Eloquent форматирует значение как «местные» часы, поэтому перед записью
 * момент приводится к UTC (ЧТЗ 7, п. 4): иначе в timestamptz попало бы
 * смещённое время и сравнение начала и окончания смены стало бы неверным.
 *
 * @property int $id
 * @property int $courier_id
 * @property int|null $zone_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CourierShift extends Model
{
    /** @use HasFactory<CourierShiftFactory> */
    use ConvertsTimestampsToUtc, HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'courier_id',
        'zone_id',
        'started_at',
        'ended_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * Моменты смены хранятся в UTC: трейт приводит их перед записью.
     *
     * @return list<string>
     */
    protected function utcAttributes(): array
    {
        return ['started_at', 'ended_at'];
    }

    /**
     * Смена ещё не закрыта.
     */
    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * Открытые смены: у курьера такая одна.
     *
     * @param Builder<CourierShift> $query
     * @return Builder<CourierShift>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('ended_at');
    }

    /**
     * Курьер смены.
     *
     * @return BelongsTo<User, $this>
     */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_id');
    }

    /**
     * Зона смены; null — смена подходит для любой зоны (FR-DSP-114).
     *
     * @return BelongsTo<ServiceZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ServiceZone::class, 'zone_id');
    }
}
