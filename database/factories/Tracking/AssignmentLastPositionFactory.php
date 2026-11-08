<?php

declare(strict_types=1);

namespace Database\Factories\Tracking;

use App\Models\Dispatch\Assignment;
use App\Models\Tracking\AssignmentLastPosition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssignmentLastPosition>
 */
class AssignmentLastPositionFactory extends Factory
{
    protected $model = AssignmentLastPosition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'assignment_id' => Assignment::factory(),
            'latitude' => 55.755826,
            'longitude' => 37.6173,
            'recorded_at' => now(),
        ];
    }
}
