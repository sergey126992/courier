<?php

declare(strict_types=1);

use App\Domain\Identity\Role;
use App\Models\Dispatch\Assignment;
use App\Models\Dispatch\CourierShift;
use App\Models\Geography\ServiceZone;
use App\Models\Order\OutboxMessage;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

/**
 * Действующий курьер с токеном.
 */
function shiftAsCourier(): User
{
    $courier = User::factory()->courier()->create();
    Sanctum::actingAs($courier);

    return $courier;
}

/**
 * Пользователь указанной роли для проверки прав.
 */
function shiftUser(string $role): User
{
    return match ($role) {
        'sender' => User::factory()->sender()->create(),
        'dispatcher' => User::factory()->dispatcher()->create(),
        'admin' => User::factory()->admin()->create(),
        default => User::factory()->create(['role' => Role::Courier]),
    };
}

it('открывает смену и пишет событие начала в outbox', function (): void {
    $courier = shiftAsCourier();

    $response = $this->postJson('/api/v1/courier/shifts');

    $response->assertCreated()
        ->assertJsonPath('data.courier_id', $courier->id)
        ->assertJsonPath('data.zone_id', null)
        ->assertJsonPath('data.ended_at', null)
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.is_open', true);

    $shift = CourierShift::query()->sole();

    expect($shift->courier_id)->toBe($courier->id)
        ->and($shift->zone_id)->toBeNull()
        ->and($shift->ended_at)->toBeNull()
        ->and($shift->started_at)->not->toBeNull();

    $this->assertDatabaseHas('outbox_messages', [
        'aggregate_type' => 'courier_shift',
        'aggregate_id' => $shift->id,
        'event_type' => 'courier.shift.started',
    ]);

    $event = OutboxMessage::query()->sole();

    expect($event->payload)->toMatchArray([
        'shift_id' => $shift->id,
        'courier_id' => $courier->id,
        'zone_id' => null,
    ]);
});

it('открывает смену с ограничением по зоне и без неё', function (): void {
    $courier = shiftAsCourier();
    $zone = ServiceZone::factory()->create();

    $this->postJson('/api/v1/courier/shifts', ['zone_id' => $zone->id])
        ->assertCreated()
        ->assertJsonPath('data.zone_id', $zone->id);

    $shift = CourierShift::query()->sole();

    $this->postJson('/api/v1/courier/shifts/'.$shift->id.'/close')->assertOk();

    $this->postJson('/api/v1/courier/shifts')
        ->assertCreated()
        ->assertJsonPath('data.zone_id', null);

    expect(CourierShift::query()->where('courier_id', $courier->id)->count())->toBe(2);
});

it('отклоняет несуществующую зону', function (): void {
    shiftAsCourier();

    $this->postJson('/api/v1/courier/shifts', ['zone_id' => 999999])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('details.0.field', 'zone_id');
});

it('отклоняет поля состояния смены', function (): void {
    shiftAsCourier();

    $this->postJson('/api/v1/courier/shifts', ['started_at' => now()->toIso8601String()])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');
});

it('запрещает повторное открытие смены', function (): void {
    $courier = shiftAsCourier();
    CourierShift::factory()->open()->for($courier, 'courier')->create(['started_at' => now()->subMinutes(30)]);

    $this->postJson('/api/v1/courier/shifts')
        ->assertStatus(409)
        ->assertJsonPath('code', 'COURIER_SHIFT_ALREADY_OPEN');

    expect(CourierShift::query()->count())->toBe(1);
});

it('запрещает смену, пересекающуюся с существующей', function (): void {
    $courier = shiftAsCourier();

    // Смена ещё не закончилась: новая, начинающаяся сейчас, пересекается с ней.
    CourierShift::factory()
        ->for($courier, 'courier')
        ->startedAt(now()->subHour())
        ->closed(now()->addHour())
        ->create();

    $this->postJson('/api/v1/courier/shifts')
        ->assertStatus(409)
        ->assertJsonPath('code', 'COURIER_SHIFT_OVERLAP');

    expect(CourierShift::query()->count())->toBe(1);
});

it('закрывает смену, заполняет окончание и пишет событие', function (): void {
    $courier = shiftAsCourier();
    $shift = CourierShift::factory()->open()->for($courier, 'courier')->create(['started_at' => now()->subMinutes(30)]);

    $response = $this->postJson('/api/v1/courier/shifts/'.$shift->id.'/close');

    $response->assertOk()
        ->assertJsonPath('data.status', 'closed')
        ->assertJsonPath('data.is_open', false);

    expect($shift->fresh()?->ended_at)->not->toBeNull();
    expect((string) $response->json('data.ended_at'))->not->toBe('');

    $this->assertDatabaseHas('outbox_messages', [
        'aggregate_type' => 'courier_shift',
        'aggregate_id' => $shift->id,
        'event_type' => 'courier.shift.ended',
    ]);
});

it('запрещает повторное закрытие смены', function (): void {
    $courier = shiftAsCourier();
    $shift = CourierShift::factory()->open()->for($courier, 'courier')->create(['started_at' => now()->subMinutes(30)]);

    $this->postJson('/api/v1/courier/shifts/'.$shift->id.'/close')->assertOk();

    $this->postJson('/api/v1/courier/shifts/'.$shift->id.'/close')
        ->assertStatus(409)
        ->assertJsonPath('code', 'COURIER_SHIFT_NOT_OPEN');
});

it('запрещает закрытие смены при активном назначении', function (): void {
    $courier = shiftAsCourier();
    $shift = CourierShift::factory()->open()->for($courier, 'courier')->create(['started_at' => now()->subMinutes(30)]);

    Assignment::factory()->active()->for($courier, 'courier')->create();

    $this->postJson('/api/v1/courier/shifts/'.$shift->id.'/close')
        ->assertStatus(409)
        ->assertJsonPath('code', 'SHIFT_HAS_ACTIVE_ASSIGNMENT');

    expect($shift->fresh()?->ended_at)->toBeNull();
});

it('запрещает открытие смены неактивному курьеру', function (): void {
    $courier = shiftAsCourier();
    $courier->courierProfile()->update(['is_active' => false]);

    $this->postJson('/api/v1/courier/shifts')
        ->assertStatus(409)
        ->assertJsonPath('code', 'COURIER_INACTIVE');

    expect(CourierShift::query()->count())->toBe(0);
});

it('отказывает в доступе курьеру без профиля', function (): void {
    Sanctum::actingAs(User::factory()->create(['role' => Role::Courier]));

    $this->postJson('/api/v1/courier/shifts')
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    expect(CourierShift::query()->count())->toBe(0);
});

it('закрывает смены для ролей кроме курьера', function (string $role): void {
    $shift = CourierShift::factory()->open()->create();

    Sanctum::actingAs(shiftUser($role));

    $this->postJson('/api/v1/courier/shifts')
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    $this->getJson('/api/v1/courier/shifts/current')
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    $this->postJson('/api/v1/courier/shifts/'.$shift->id.'/close')
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    expect($shift->fresh()?->ended_at)->toBeNull();
})->with([
    'отправитель' => ['sender'],
    'диспетчер' => ['dispatcher'],
    'администратор' => ['admin'],
]);

it('не раскрывает чужую смену: курьер получает 404', function (): void {
    $shift = CourierShift::factory()->open()->create();

    shiftAsCourier();

    $this->postJson('/api/v1/courier/shifts/'.$shift->id.'/close')
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');

    expect($shift->fresh()?->ended_at)->toBeNull();
});

it('возвращает 404, когда открытой смены нет', function (): void {
    shiftAsCourier();

    $this->getJson('/api/v1/courier/shifts/current')
        ->assertStatus(404)
        ->assertJsonPath('code', 'NOT_FOUND');
});

it('отдаёт текущую открытую смену', function (): void {
    $courier = shiftAsCourier();
    $shift = CourierShift::factory()->open()->for($courier, 'courier')->create(['started_at' => now()->subHour()]);

    // Закрытая смена в выдачу не попадает.
    CourierShift::factory()->closed()->for($courier, 'courier')->create([
        'started_at' => now()->subDays(2),
        'ended_at' => now()->subDays(2)->addHours(4),
    ]);

    $this->getJson('/api/v1/courier/shifts/current')
        ->assertOk()
        ->assertJsonPath('data.id', $shift->id)
        ->assertJsonPath('data.courier_id', $courier->id)
        ->assertJsonPath('data.status', 'open')
        ->assertJsonPath('data.is_open', true);
});

it('требует токен для смены', function (): void {
    $this->postJson('/api/v1/courier/shifts')
        ->assertStatus(401)
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});
