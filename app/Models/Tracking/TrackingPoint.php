<?php

declare(strict_types=1);

namespace App\Models\Tracking;

use App\Domain\Tracking\Exceptions\TrackingPointIsImmutable;
use App\Domain\Tracking\TrackingPointSource;
use App\Models\Concerns\ConvertsTimestampsToUtc;
use App\Models\Dispatch\Assignment;
use Database\Factories\Tracking\TrackingPointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Геоточка назначения: только добавление (FR-TRK-102).
 *
 * Защита — на уровне модели, по образцу OrderStatusHistory: переопределение
 * save/update/delete отклоняет попытку даже из кода, который не вызывает
 * события вручную. Массовое удаление через Query Builder остаётся возможным
 * только в служебных задачах и в рабочем контуре не используется.
 *
 * @property int $id
 * @property int $assignment_id
 * @property float $latitude
 * @property float $longitude
 * @property Carbon $recorded_at
 * @property TrackingPointSource $source
 * @property Carbon $created_at
 */
class TrackingPoint extends Model
{
    /** @use HasFactory<TrackingPointFactory> */
    use ConvertsTimestampsToUtc, HasFactory;

    /**
     * У точки нет времени изменения: запись создаётся один раз.
     */
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'assignment_id',
        'latitude',
        'longitude',
        'recorded_at',
        'source',
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
            'source' => TrackingPointSource::class,
            'created_at' => 'datetime',
        ];
    }

    /**
     * Время точки хранится в UTC: трейт приводит его перед записью.
     *
     * @return list<string>
     */
    protected function utcAttributes(): array
    {
        return ['recorded_at'];
    }

    /**
     * Назначение, к которому относится точка.
     *
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'assignment_id');
    }

    /**
     * @throws TrackingPointIsImmutable точка уже сохранена
     */
    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw TrackingPointIsImmutable::forUpdate($this->getKey());
        }

        return parent::save($options);
    }

    /**
     * @throws TrackingPointIsImmutable изменение запрещено
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw TrackingPointIsImmutable::forUpdate($this->getKey());
    }

    /**
     * @throws TrackingPointIsImmutable удаление запрещено
     */
    public function delete(): ?bool
    {
        throw TrackingPointIsImmutable::forDelete($this->getKey());
    }
}
