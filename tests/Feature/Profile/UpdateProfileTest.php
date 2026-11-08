<?php

declare(strict_types=1);

use App\Domain\Identity\Role;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('сохраняет разрешённые поля отправителя', function (): void {
    $user = User::factory()->sender()->create(['name' => 'Старое Имя']);

    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/profile', [
        'name' => 'Новое Имя',
        'phone' => '+7 999 111-22-33',
        'company' => 'ООО «Вектор»',
        'default_address' => [
            'district' => 'Заречный',
            'street' => 'Мира',
            'house' => '7',
            'latitude' => 54.5,
            'longitude' => 36.25,
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Новое Имя')
        ->assertJsonPath('data.profile.phone', '+7 999 111-22-33')
        ->assertJsonPath('data.profile.company', 'ООО «Вектор»')
        ->assertJsonPath('data.profile.default_address.district', 'Заречный');

    expect($user->refresh()->name)->toBe('Новое Имя');

    $this->assertDatabaseHas('sender_profiles', [
        'user_id' => $user->id,
        'phone' => '+7 999 111-22-33',
        'company' => 'ООО «Вектор»',
    ]);

    $address = $user->senderProfile()->sole()->default_address ?? [];

    expect($address)->toMatchArray([
        'district' => 'Заречный',
        'street' => 'Мира',
        'house' => '7',
    ]);
});

it('создаёт профиль отправителя, если его ещё нет', function (): void {
    $user = User::factory()->create(['role' => Role::Sender]);

    Sanctum::actingAs($user);

    $this->assertDatabaseMissing('sender_profiles', ['user_id' => $user->id]);

    $this->patchJson('/api/v1/profile', ['phone' => '+7 911 000-00-00'])
        ->assertOk()
        ->assertJsonPath('data.profile.phone', '+7 911 000-00-00');

    $this->assertDatabaseHas('sender_profiles', [
        'user_id' => $user->id,
        'phone' => '+7 911 000-00-00',
    ]);
});

it('отклоняет некорректные данные с деталями по полям', function (): void {
    Sanctum::actingAs(User::factory()->sender()->create());

    $response = $this->patchJson('/api/v1/profile', [
        'name' => 'Я',
        'default_address' => [
            'latitude' => 200,
            'longitude' => 'строка',
        ],
    ]);

    $response->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    $fields = array_column((array) $response->json('details'), 'field');

    expect($fields)->toContain('name')
        ->and($fields)->toContain('default_address.latitude')
        ->and($fields)->toContain('default_address.longitude')
        ->and((string) $response->getContent())->toContain('Ошибка валидации')
        ->and((string) $response->getContent())->toContain('широта');
});

it('не меняет роль через профиль', function (): void {
    $user = User::factory()->sender()->create();

    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/profile', ['role' => 'admin'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'sender']);
});

it('даёт курьеру изменить только имя', function (): void {
    $user = User::factory()->courier()->create(['name' => 'Курьер Старый']);

    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/profile', ['name' => 'Курьер Новый'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Курьер Новый');

    expect($user->refresh()->name)->toBe('Курьер Новый');
});

it('запрещает курьеру менять транспорт через профиль', function (): void {
    $user = User::factory()->courier()->create();

    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/profile', ['transport_type' => 'car'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    $this->assertDatabaseHas('courier_profiles', [
        'user_id' => $user->id,
        'transport_type' => 'on_foot',
    ]);
});

it('закрывает изменение профиля неподтверждённому пользователю', function (): void {
    $user = User::factory()->sender()->unverified()->create(['name' => 'Старое Имя']);

    Sanctum::actingAs($user);

    $this->patchJson('/api/v1/profile', ['name' => 'Новое Имя'])
        ->assertStatus(403)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');

    expect($user->refresh()->name)->toBe('Старое Имя');
});

it('позволяет неподтверждённому пользователю смотреть свой профиль', function (): void {
    $user = User::factory()->sender()->unverified()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/profile')
        ->assertOk()
        ->assertJsonPath('data.email_verified_at', null);
});

it('запрещает диспетчеру менять профиль', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();

    Sanctum::actingAs($dispatcher);

    $this->patchJson('/api/v1/profile', ['name' => 'Новое Имя'])
        ->assertStatus(403)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'FORBIDDEN');
});

it('требует токен для изменения профиля', function (): void {
    $this->patchJson('/api/v1/profile', ['name' => 'Новое Имя'])
        ->assertStatus(401)
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});
