<?php

declare(strict_types=1);

use App\Domain\Identity\Role;
use App\Models\SenderProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('создаёт сидом пользователя каждой роли', function (): void {
    $this->seed();

    $expected = [
        ['sender@courier.test', 'sender'],
        ['courier@courier.test', 'courier'],
        ['dispatcher@courier.test', 'dispatcher'],
        ['admin@courier.test', 'admin'],
    ];

    foreach ($expected as [$email, $role]) {
        $this->assertDatabaseHas('users', ['email' => $email, 'role' => $role]);
    }
});

it('создаёт сидом профили отправителя и курьера', function (): void {
    $this->seed();

    $sender = User::query()->where('email', 'sender@courier.test')->sole();
    $this->assertDatabaseHas('sender_profiles', ['user_id' => $sender->id]);

    $courier = User::query()->where('email', 'courier@courier.test')->sole();
    $this->assertDatabaseHas('courier_profiles', [
        'user_id' => $courier->id,
        'transport_type' => 'light',
        'light_vehicle_kind' => 'bicycle',
        'is_active' => true,
    ]);
});

it('не допускает второй профиль отправителя', function (): void {
    $user = User::factory()->sender()->create();

    expect(static fn () => SenderProfile::factory()->create(['user_id' => $user->id]))
        ->toThrow(QueryException::class);
});

it('не допускает вид лёгкого транспорта при типе «пешком»', function (): void {
    $user = User::factory()->create();

    expect(static fn () => DB::table('courier_profiles')->insert([
        'user_id' => $user->id,
        'transport_type' => 'on_foot',
        'light_vehicle_kind' => 'bicycle',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('не допускает роль вне набора значений', function (): void {
    expect(static fn () => DB::table('users')->insert([
        'name' => 'Пользователь',
        'email' => 'wrong-role@courier.test',
        'password' => 'secret',
        'role' => 'superuser',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('отдаёт роль отправителя у нового пользователя без перезагрузки', function (): void {
    $user = User::create([
        'name' => 'Пётр',
        'email' => 'new-user-role@courier.test',
        'password' => 'secret',
    ]);

    // Каст перечисления работает сразу: значение по умолчанию задано и в модели, и в базе.
    expect($user->role)->toBe(Role::Sender);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'sender']);
});

it('сохраняет роль, назначенную явно', function (): void {
    $user = User::factory()->create();

    $user->role = Role::Admin;
    $user->save();

    expect($user->fresh()?->role)->toBe(Role::Admin);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'admin']);
});

it('не меняет роль через массовое присвоение', function (): void {
    $user = User::create([
        'name' => 'Иван',
        'email' => 'mass-assignment@courier.test',
        'password' => 'secret',
        'role' => 'admin',
    ]);

    expect($user->role)->toBe(Role::Sender);
    $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'sender']);
});
