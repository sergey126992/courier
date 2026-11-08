<?php

declare(strict_types=1);

use App\Domain\Tracking\Exceptions\TrackingPointIsImmutable;
use App\Domain\Tracking\TrackingPointSource;
use App\Models\Dispatch\Assignment;
use App\Models\Tracking\AssignmentLastPosition;
use App\Models\Tracking\CourierLastPosition;
use App\Models\Tracking\TrackingPoint;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Строка геоточки в обход модели: проверка ограничений схемы.
 *
 * @return array<string, mixed>
 */
function trackingPointRow(int $assignmentId, float $latitude, float $longitude): array
{
    return [
        'assignment_id' => $assignmentId,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'recorded_at' => now(),
        'source' => 'courier',
        'created_at' => now(),
    ];
}

it('создаёт таблицу геоточек без времени изменения', function (): void {
    expect(Schema::hasColumns('tracking_points', [
        'id', 'assignment_id', 'latitude', 'longitude', 'recorded_at', 'source', 'created_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumn('tracking_points', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumns('assignment_last_positions', [
            'id', 'assignment_id', 'latitude', 'longitude', 'recorded_at', 'created_at', 'updated_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('courier_last_positions', [
            'id', 'courier_id', 'latitude', 'longitude', 'recorded_at', 'created_at', 'updated_at',
        ]))->toBeTrue();
});

it('запрещает изменение сохранённой геоточки', function (): void {
    $point = TrackingPoint::factory()->create();
    $point->latitude = 55.75;

    expect(static fn () => $point->save())->toThrow(TrackingPointIsImmutable::class)
        ->and(DB::table('tracking_points')->where('id', $point->id)->value('latitude'))->not->toBeNull();
});

it('запрещает массовое изменение геоточки', function (): void {
    $point = TrackingPoint::factory()->create();

    expect(static fn () => $point->update(['latitude' => 55.75]))
        ->toThrow(TrackingPointIsImmutable::class);
});

it('запрещает удаление геоточки', function (): void {
    $point = TrackingPoint::factory()->create();

    expect(static fn () => $point->delete())->toThrow(TrackingPointIsImmutable::class)
        ->and(TrackingPoint::query()->whereKey($point->id)->exists())->toBeTrue();
});

it('приводит источник и координаты к доменным типам', function (): void {
    $point = TrackingPoint::factory()->create();

    expect($point->source)->toBe(TrackingPointSource::Courier)
        ->and($point->latitude)->toBe(55.755826)
        ->and($point->longitude)->toBe(37.6173);
});

it('отдаёт ленту назначения в порядке времени', function (): void {
    $assignment = Assignment::factory()->accepted()->create();

    TrackingPoint::factory()->for($assignment, 'assignment')->recordedAt(Carbon::parse('2026-05-01 10:05:00', 'UTC'))->create();
    TrackingPoint::factory()->for($assignment, 'assignment')->recordedAt(Carbon::parse('2026-05-01 10:01:00', 'UTC'))->create();

    $recordedAt = $assignment->points()->pluck('recorded_at')->map(static fn ($value): string => (string) $value)->all();

    expect($recordedAt)->toBe(['2026-05-01 10:01:00', '2026-05-01 10:05:00']);
});

it('отсекает широту вне допустимого диапазона', function (): void {
    $assignment = Assignment::factory()->create();

    expect(static fn () => DB::table('tracking_points')
        ->insert(trackingPointRow($assignment->id, 91.0, 37.6173)))
        ->toThrow(QueryException::class);
});

it('отсекает долготу вне допустимого диапазона', function (): void {
    $assignment = Assignment::factory()->create();

    expect(static fn () => DB::table('tracking_points')
        ->insert(trackingPointRow($assignment->id, 55.755826, 181.0)))
        ->toThrow(QueryException::class);
});

it('обновляет последнюю позицию назначения вставкой с заменой', function (): void {
    $assignment = Assignment::factory()->create();

    $rows = [
        ['assignment_id' => $assignment->id, 'latitude' => 55.755826, 'longitude' => 37.6173, 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['assignment_id' => $assignment->id, 'latitude' => 55.760000, 'longitude' => 37.620000, 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ];

    foreach ($rows as $row) {
        AssignmentLastPosition::query()->upsert([$row], ['assignment_id'], ['latitude', 'longitude', 'recorded_at', 'updated_at']);
    }

    $positions = AssignmentLastPosition::query()->where('assignment_id', $assignment->id)->get();

    expect($positions)->toHaveCount(1)
        ->and($positions->first()?->latitude)->toBe(55.76);
});

it('обновляет последнюю позицию курьера вставкой с заменой', function (): void {
    $courier = User::factory()->courier()->create();

    $rows = [
        ['courier_id' => $courier->id, 'latitude' => 55.755826, 'longitude' => 37.6173, 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now()],
        ['courier_id' => $courier->id, 'latitude' => 55.770000, 'longitude' => 37.630000, 'recorded_at' => now(), 'created_at' => now(), 'updated_at' => now()],
    ];

    foreach ($rows as $row) {
        CourierLastPosition::query()->upsert([$row], ['courier_id'], ['latitude', 'longitude', 'recorded_at', 'updated_at']);
    }

    expect(CourierLastPosition::query()->where('courier_id', $courier->id)->count())->toBe(1);
});

it('запрещает удаление назначения, у которого есть геоточка', function (): void {
    $point = TrackingPoint::factory()->create();
    $assignment = Assignment::query()->findOrFail($point->assignment_id);

    expect(static fn () => DB::table('assignments')->where('id', $assignment->id)->delete())
        ->toThrow(QueryException::class);
});
