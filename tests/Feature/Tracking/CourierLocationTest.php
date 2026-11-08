<?php

declare(strict_types=1);

use App\Models\Dispatch\Assignment;
use App\Models\Dispatch\CourierShift;
use App\Models\Tracking\AssignmentLastPosition;
use App\Models\Tracking\CourierLastPosition;
use App\Models\Tracking\TrackingPoint;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Действующий курьер с токеном.
 */
function courierLocationCourier(): User
{
    $courier = User::factory()->courier()->create();
    Sanctum::actingAs($courier);

    return $courier;
}

/**
 * Тело heartbeat.
 *
 * @return array<string, mixed>
 */
function courierLocationBody(?string $recordedAt = null): array
{
    $body = [
        'latitude' => 55.755826,
        'longitude' => 37.617300,
    ];

    if ($recordedAt !== null) {
        $body['recorded_at'] = $recordedAt;
    }

    return $body;
}

it('принимает heartbeat при открытой смене и пишет только позицию курьера', function (): void {
    $courier = courierLocationCourier();
    CourierShift::factory()->for($courier, 'courier')->open()->create();

    $this->postJson('/api/v1/courier/location', courierLocationBody(now()->subMinute()->toIso8601String()))
        ->assertStatus(202)
        ->assertJsonPath('data.accepted', true)
        ->assertJsonPath('data.recorded_at', static fn (mixed $value): bool => is_string($value));

    $position = CourierLastPosition::query()->where('courier_id', $courier->id)->sole();

    expect($position->latitude)->toBe(55.755826)
        ->and($position->longitude)->toBe(37.6173)
        ->and(TrackingPoint::query()->count())->toBe(0)
        ->and(AssignmentLastPosition::query()->count())->toBe(0);
});

it('отклоняет heartbeat без открытой смены', function (): void {
    $courier = courierLocationCourier();

    $this->postJson('/api/v1/courier/location', courierLocationBody(now()->subMinute()->toIso8601String()))
        ->assertStatus(409)
        ->assertJsonPath('code', 'COURIER_SHIFT_NOT_OPEN');

    expect(CourierLastPosition::query()->count())->toBe(0);
});

it('принимает heartbeat при активном назначении и не меняет его историю', function (): void {
    $courier = courierLocationCourier();
    CourierShift::factory()->for($courier, 'courier')->open()->create();

    $assignment = Assignment::factory()->for($courier, 'courier')->accepted()->create();
    TrackingPoint::factory()->for($assignment, 'assignment')->recordedAt(now()->subMinutes(5))->create();

    $this->postJson('/api/v1/courier/location', courierLocationBody(now()->subMinute()->toIso8601String()))
        ->assertStatus(202);

    expect(TrackingPoint::query()->where('assignment_id', $assignment->id)->count())->toBe(1)
        ->and(AssignmentLastPosition::query()->count())->toBe(0)
        ->and(CourierLastPosition::query()->where('courier_id', $courier->id)->count())->toBe(1);
});

it('запрещает heartbeat не курьеру', function (): void {
    Sanctum::actingAs(User::factory()->sender()->create());

    $this->postJson('/api/v1/courier/location', courierLocationBody(now()->subMinute()->toIso8601String()))
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});
