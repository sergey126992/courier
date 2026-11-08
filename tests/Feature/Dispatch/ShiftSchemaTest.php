<?php

declare(strict_types=1);

use App\Models\Dispatch\CourierShift;
use App\Models\Geography\ServiceZone;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Смена курьера в требуемых границах времени.
 *
 * @return array<string, mixed>
 */
function dispatchShiftRow(Carbon $startedAt, ?Carbon $endedAt = null, ?int $courierId = null): array
{
    return [
        'courier_id' => $courierId ?? User::factory()->courier()->create()->id,
        'zone_id' => null,
        'started_at' => $startedAt,
        'ended_at' => $endedAt,
        'created_at' => now(),
        'updated_at' => now(),
    ];
}

it('создаёт таблицу смен с ожидаемыми колонками', function (): void {
    expect(Schema::hasColumns('courier_shifts', [
        'id', 'courier_id', 'zone_id', 'started_at', 'ended_at', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('отсекает ограничением базы новую смену, пересекающуюся с открытой', function (): void {
    $courier = User::factory()->courier()->create();

    CourierShift::factory()->for($courier, 'courier')->startedAt(Carbon::parse('2026-05-01 09:00:00', 'UTC'))->create();

    // Открытая смена тянется до бесконечности, поэтому вторая смена пересекается с ней.
    expect(static fn () => CourierShift::factory()
        ->for($courier, 'courier')
        ->startedAt(Carbon::parse('2026-05-01 12:00:00', 'UTC'))
        ->create())->toThrow(QueryException::class);
});

it('отсекает ограничением базы пересечение двух закрытых смен', function (): void {
    $courier = User::factory()->courier()->create();

    DB::table('courier_shifts')->insert(dispatchShiftRow(
        Carbon::parse('2026-05-01 09:00:00', 'UTC'),
        Carbon::parse('2026-05-01 12:00:00', 'UTC'),
        $courier->id,
    ));

    expect(static fn () => DB::table('courier_shifts')->insert(dispatchShiftRow(
        Carbon::parse('2026-05-01 11:00:00', 'UTC'),
        Carbon::parse('2026-05-01 13:00:00', 'UTC'),
        $courier->id,
    )))->toThrow(QueryException::class);
});

it('разрешает непересекающиеся смены одного курьера', function (): void {
    $courier = User::factory()->courier()->create();

    DB::table('courier_shifts')->insert(dispatchShiftRow(
        Carbon::parse('2026-05-01 09:00:00', 'UTC'),
        Carbon::parse('2026-05-01 12:00:00', 'UTC'),
        $courier->id,
    ));

    // Смена, начинающаяся ровно в момент окончания предыдущей, не пересекается с ней.
    DB::table('courier_shifts')->insert(dispatchShiftRow(
        Carbon::parse('2026-05-01 12:00:00', 'UTC'),
        Carbon::parse('2026-05-01 15:00:00', 'UTC'),
        $courier->id,
    ));

    expect(DB::table('courier_shifts')->where('courier_id', $courier->id)->count())->toBe(2);
});

it('разрешает пересекающиеся смены разным курьерам', function (): void {
    $startedAt = Carbon::parse('2026-05-01 09:00:00', 'UTC');

    CourierShift::factory()->startedAt($startedAt)->create();
    CourierShift::factory()->startedAt($startedAt)->create();

    expect(CourierShift::query()->count())->toBe(2);
});

it('обходится без зоны смены и обнуляет зону при её удалении', function (): void {
    $zone = ServiceZone::factory()->create();
    $shift = CourierShift::factory()->for($zone, 'zone')->create();

    expect($shift->zone_id)->toBe($zone->id)
        ->and($shift->isOpen())->toBeTrue();

    $zone->delete();

    expect(CourierShift::query()->findOrFail($shift->id)->zone_id)->toBeNull();
});

it('запрещает удаление курьера, у которого есть смена', function (): void {
    $shift = CourierShift::factory()->create();
    $courier = User::query()->findOrFail($shift->courier_id);

    expect(static fn () => $courier->delete())->toThrow(QueryException::class);
});

it('скоуп открытых смен не возвращает закрытые', function (): void {
    $open = CourierShift::factory()->open()->create();
    CourierShift::factory()->closed()->create();

    $shiftIds = CourierShift::query()->open()->pluck('id')->all();

    expect($shiftIds)->toBe([$open->id]);
});
