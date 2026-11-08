<?php

declare(strict_types=1);

namespace Database\Factories\Tracking;

use App\Domain\Tracking\TrackingPointSource;
use App\Models\Dispatch\Assignment;
use App\Models\Tracking\TrackingPoint;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<TrackingPoint>
 */
class TrackingPointFactory extends Factory
{
    protected $model = TrackingPoint::class;

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
            'source' => TrackingPointSource::Courier,
        ];
    }

    /**
     * Точка с заданным временем: нужно для проверки порядка ленты.
     */
    public function recordedAt(Carbon $recordedAt): static
    {
        return $this->state(fn (array $attributes): array => [
            'recorded_at' => $recordedAt,
        ]);
    }

    /**
     * Точка с координатами: проверка диапазонов и ленты.
     */
    public function at(float $latitude, float $longitude): static
    {
        return $this->state(fn (array $attributes): array => [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);
    }
}
