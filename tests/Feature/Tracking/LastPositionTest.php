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
function lastPositionCourier(): User
{
    $courier = User::factory()->courier()->create();
    Sanctum::actingAs($courier);

    return $courier;
}

/**
 * Тело геоточки с заданными координатами и временем.
 *
 * @return array<string, mixed>
 */
function lastPositionBody(float $latitude, float $longitude, string $recordedAt): array
{
    return [
        'latitude' => $latitude,
        'longitude' => $longitude,
        'recorded_at' => $recordedAt,
    ];
}

it('держит по одной строке последней позиции при нескольких точках', function (): void {
    $courier = lastPositionCourier();
    $assignment = Assignment::factory()->for($courier, 'courier')->accepted()->create();
    $url = '/api/v1/assignments/'.$assignment->id.'/points';

    $this->postJson($url, lastPositionBody(55.760120, 37.618400, now()->subMinutes(5)->toIso8601String()))
        ->assertStatus(202);
    $this->postJson($url, lastPositionBody(55.770000, 37.630000, now()->subMinutes(4)->toIso8601String()))
        ->assertStatus(202);

    $assignmentPositions = AssignmentLastPosition::query()->where('assignment_id', $assignment->id)->get();
    $courierPositions = CourierLastPosition::query()->where('courier_id', $courier->id)->get();

    expect($assignmentPositions)->toHaveCount(1)
        ->and($courierPositions)->toHaveCount(1)
        ->and($assignmentPositions->first()?->latitude)->toBe(55.77)
        ->and($assignmentPositions->first()?->longitude)->toBe(37.63)
        ->and($courierPositions->first()?->latitude)->toBe(55.77)
        ->and(TrackingPoint::query()->where('assignment_id', $assignment->id)->count())->toBe(2);
});

it('не создаёт дубль последней позиции курьера после heartbeat', function (): void {
    $courier = lastPositionCourier();
    CourierShift::factory()->for($courier, 'courier')->open()->create();

    $assignment = Assignment::factory()->for($courier, 'courier')->accepted()->create();

    $this->postJson(
        '/api/v1/assignments/'.$assignment->id.'/points',
        lastPositionBody(55.760120, 37.618400, now()->subMinutes(5)->toIso8601String()),
    )->assertStatus(202);

    $this->postJson(
        '/api/v1/courier/location',
        lastPositionBody(55.770000, 37.630000, now()->subMinutes(4)->toIso8601String()),
    )->assertStatus(202);

    $courierPositions = CourierLastPosition::query()->where('courier_id', $courier->id)->get();

    expect($courierPositions)->toHaveCount(1)
        ->and($courierPositions->first()?->latitude)->toBe(55.77)
        ->and(AssignmentLastPosition::query()->where('assignment_id', $assignment->id)->count())->toBe(1);
});

it('не увеличивает число строк последних позиций с каждой новой точкой', function (): void {
    $courier = lastPositionCourier();
    $assignment = Assignment::factory()->for($courier, 'courier')->accepted()->create();
    $url = '/api/v1/assignments/'.$assignment->id.'/points';

    $points = [
        5 => [55.761000, 37.618400],
        4 => [55.762000, 37.618400],
        3 => [55.763000, 37.618400],
        2 => [55.764000, 37.618400],
        1 => [55.765000, 37.618400],
    ];

    foreach ($points as $minutesAgo => [$latitude, $longitude]) {
        $this->postJson(
            $url,
            lastPositionBody($latitude, $longitude, now()->subMinutes($minutesAgo)->toIso8601String()),
        )->assertStatus(202);
    }

    expect(TrackingPoint::query()->where('assignment_id', $assignment->id)->count())->toBe(5)
        ->and(AssignmentLastPosition::query()->where('assignment_id', $assignment->id)->count())->toBe(1)
        ->and(CourierLastPosition::query()->where('courier_id', $courier->id)->count())->toBe(1);
});
