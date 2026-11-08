<?php

declare(strict_types=1);

namespace App\Models\Tracking;

use App\Models\Dispatch\Assignment;
use Database\Factories\Tracking\AssignmentLastPositionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Последняя позиция по назначению (FR-TRK-111…116).
 *
 * Одна строка на назначение: обновляется вставкой с заменой по уникальному
 * ключу assignment_id, поэтому дубликатов не появляется.
 *
 * @property int $id
 * @property int $assignment_id
 * @property float $latitude
 * @property float $longitude
 * @property Carbon $recorded_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class AssignmentLastPosition extends Model
{
    /** @use HasFactory<AssignmentLastPositionFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'assignment_id',
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
     * Назначение, позиция которого хранится.
     *
     * @return BelongsTo<Assignment, $this>
     */
    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class, 'assignment_id');
    }
}
