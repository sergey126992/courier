<?php

declare(strict_types=1);

namespace Database\Factories\Tracking;

use App\Models\Tracking\CourierLastPosition;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CourierLastPosition>
 */
class CourierLastPositionFactory extends Factory
{
    protected $model = CourierLastPosition::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'courier_id' => User::factory()->courier(),
            'latitude' => 55.755826,
            'longitude' => 37.6173,
            'recorded_at' => now(),
        ];
    }
}
