<?php

declare(strict_types=1);

use App\Models\Dispatch\Assignment;
use App\Models\Tracking\AssignmentLastPosition;
use App\Models\Tracking\CourierLastPosition;
use App\Models\Tracking\TrackingPoint;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

/**
 * Действующий курьер с токеном.
 */
function trackingPointsCourier(): User
{
    $courier = User::factory()->courier()->create();
    Sanctum::actingAs($courier);

    return $courier;
}

/**
 * Адрес приёма геоточки.
 */
function trackingPointsUrl(Assignment $assignment): string
{
    return '/api/v1/assignments/'.$assignment->id.'/points';
}

/**
 * Тело геоточки с необязательным временем.
 *
 * @return array<string, mixed>
 */
function trackingPointBody(?string $recordedAt = null, float $latitude = 55.760120, float $longitude = 37.618400): array
{
    $body = [
        'latitude' => $latitude,
        'longitude' => $longitude,
    ];

    if ($recordedAt !== null) {
        $body['recorded_at'] = $recordedAt;
    }

    return $body;
}

it('принимает точку по принятому назначению и обновляет обе последние позиции', function (): void {
    $courier = trackingPointsCourier();
    $assignment = Assignment::factory()->for($courier, 'courier')->accepted()->create();

    $response = $this->postJson(
        trackingPointsUrl($assignment),
        trackingPointBody(now()->subMinute()->toIso8601String()),
    );

    $response->assertStatus(202)
        ->assertJsonPath('data.accepted', true)
        ->assertJsonPath('data.recorded_at', static fn (mixed $value): bool => is_string($value));

    $point = TrackingPoint::query()->where('assignment_id', $assignment->id)->sole();

    expect($point->source->value)->toBe('courier')
        ->and($point->latitude)->toBe(55.76012)
        ->and($point->longitude)->toBe(37.6184);

    $assignmentPosition = AssignmentLastPosition::query()->where('assignment_id', $assignment->id)->sole();
    $courierPosition = CourierLastPosition::query()->where('courier_id', $courier->id)->sole();

    expect($assignmentPosition->latitude)->toBe(55.76012)
        ->and($assignmentPosition->longitude)->toBe(37.6184)
        ->and($courierPosition->latitude)->toBe(55.76012)
        ->and($courierPosition->longitude)->toBe(37.6184);
});

it('подставляет текущее время, если recorded_at не передан', function (): void {
    $courier = trackingPointsCourier();
    $assignment = Assignment::factory()->for($courier, 'courier')->accepted()->create();

    $this->postJson(trackingPointsUrl($assignment), trackingPointBody())
        ->assertStatus(202)
        ->assertJsonPath('data.recorded_at', static fn (mixed $value): bool => is_string($value));

    expect(TrackingPoint::query()->where('assignment_id', $assignment->id)->exists())->toBeTrue();
});

it('отклоняет точку по назначению в статусе «назначено»', function (): void {
    $courier = trackingPointsCourier();
    $assignment = Assignment::factory()->for($courier, 'courier')->active()->create();

    $this->postJson(trackingPointsUrl($assignment), trackingPointBody(now()->subMinute()->toIso8601String()))
        ->assertStatus(409)
        ->assertJsonPath('code', 'ASSIGNMENT_NOT_ACTIVE');

    expect(TrackingPoint::query()->count())->toBe(0);
});

it('отклоняет точку по завершённому назначению', function (): void {
    $courier = trackingPointsCourier();
    $assignment = Assignment::factory()->for($courier, 'courier')->delivered()->create();

    $this->postJson(trackingPointsUrl($assignment), trackingPointBody(now()->subMinute()->toIso8601String()))
        ->assertStatus(409)
        ->assertJsonPath('code', 'ASSIGNMENT_NOT_ACTIVE');
});

it('не отдаёт чужое назначение и отвечает 404', function (): void {
    $owner = User::factory()->courier()->create();
    $assignment = Assignment::factory()->for($owner, 'courier')->accepted()->create();

    trackingPointsCourier();

    $this->postJson(trackingPointsUrl($assignment), trackingPointBody(now()->subMinute()->toIso8601String()))
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');
});

it('запрещает отправку точки не курьеру', function (): void {
    $courier = User::factory()->courier()->create();
    $assignment = Assignment::factory()->for($courier, 'courier')->accepted()->create();

    Sanctum::actingAs(User::factory()->sender()->create());

    $this->postJson(trackingPointsUrl($assignment), trackingPointBody(now()->subMinute()->toIso8601String()))
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('отклоняет координаты вне диапазона и избыточную точность', function (array $body): void {
    $courier = trackingPointsCourier();
    $assignment = Assignment::factory()->for($courier, 'courier')->accepted()->create();

    $this->postJson(trackingPointsUrl($assignment), $body)
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    expect(TrackingPoint::query()->count())->toBe(0);
})->with([
    'широта выше 90' => [['latitude' => 91.0, 'longitude' => 37.6184]],
    'широта ниже -90' => [['latitude' => -91.0, 'longitude' => 37.6184]],
    'долгота выше 180' => [['latitude' => 55.755826, 'longitude' => 181.0]],
    'точность широты выше шести знаков' => [['latitude' => 55.1234567, 'longitude' => 37.6184]],
    'точность долготы выше шести знаков' => [['latitude' => 55.755826, 'longitude' => 37.1234567]],
]);

it('отклоняет лишние поля тела', function (): void {
    $courier = trackingPointsCourier();
    $assignment = Assignment::factory()->for($courier, 'courier')->accepted()->create();

    $this->postJson(trackingPointsUrl($assignment), trackingPointBody(null) + ['speed' => 12.5])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');
});

it('отклоняет время точки из будущего сверх допуска', function (): void {
    $courier = trackingPointsCourier();
    $assignment = Assignment::factory()->for($courier, 'courier')->accepted()->create();

    $response = $this->postJson(
        trackingPointsUrl($assignment),
        trackingPointBody(now()->addMinutes(10)->toIso8601String()),
    );

    $response->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('details.0.field', 'recorded_at');

    expect(TrackingPoint::query()->count())->toBe(0);
});

it('отклоняет точку с временем не позже предыдущей', function (): void {
    $courier = trackingPointsCourier();
    $assignment = Assignment::factory()->for($courier, 'courier')->accepted()->create();

    $recordedAt = Carbon::now()->subMinute();

    TrackingPoint::factory()->for($assignment, 'assignment')->recordedAt($recordedAt)->create();

    $this->postJson(
        trackingPointsUrl($assignment),
        trackingPointBody($recordedAt->toIso8601String()),
    )
        ->assertStatus(422)
        ->assertJsonPath('code', 'TRACKING_POINT_OUT_OF_SEQUENCE');

    expect(TrackingPoint::query()->where('assignment_id', $assignment->id)->count())->toBe(1);
});

it('ограничивает частоту приёма точек и отдаёт заголовок повтора', function (): void {
    config(['rate_limits.courier_points.attempts' => 2]);

    $courier = trackingPointsCourier();
    $assignment = Assignment::factory()->for($courier, 'courier')->accepted()->create();

    $this->postJson(trackingPointsUrl($assignment), trackingPointBody(now()->subMinutes(10)->toIso8601String()))
        ->assertStatus(202);
    $this->postJson(trackingPointsUrl($assignment), trackingPointBody(now()->subMinutes(9)->toIso8601String()))
        ->assertStatus(202);

    $response = $this->postJson(
        trackingPointsUrl($assignment),
        trackingPointBody(now()->subMinutes(8)->toIso8601String()),
    );

    $response->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'RATE_LIMITED');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});
