<?php

declare(strict_types=1);

use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\Role;
use App\Domain\Identity\TransportType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('отдаёт профиль отправителя без служебных полей', function (): void {
    $user = User::factory()->sender()->create([
        'name' => 'Иван Отправитель',
        'email' => 'sender-profile@example.com',
    ]);

    $user->senderProfile()->update([
        'phone' => '+7 900 000-00-00',
        'company' => 'ООО «Ромашка»',
        'default_address' => [
            'district' => 'Центральный',
            'street' => 'Ленина',
            'house' => '1',
            'latitude' => 55.75,
            'longitude' => 37.62,
        ],
    ]);

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/profile');

    $response->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.name', 'Иван Отправитель')
        ->assertJsonPath('data.email', 'sender-profile@example.com')
        ->assertJsonPath('data.role', 'sender')
        ->assertJsonPath('data.profile.phone', '+7 900 000-00-00')
        ->assertJsonPath('data.profile.company', 'ООО «Ромашка»')
        ->assertJsonPath('data.profile.default_address.district', 'Центральный');

    $body = (string) $response->getContent();

    expect($response->json('data'))->not->toHaveKey('password')
        ->and($response->json('data.profile'))->not->toHaveKey('transport_type')
        ->and($body)->not->toContain('password')
        ->and($body)->toContain('ООО «Ромашка»');
});

it('отдаёт профиль курьера с транспортом и активностью', function (): void {
    $user = User::factory()
        ->courier(TransportType::Light, LightVehicleKind::Bicycle)
        ->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/profile')
        ->assertOk()
        ->assertJsonPath('data.role', 'courier')
        ->assertJsonPath('data.profile.transport_type', 'light')
        ->assertJsonPath('data.profile.light_vehicle_kind', 'bicycle')
        ->assertJsonPath('data.profile.is_active', true);
});

it('не падает, если профиля отправителя ещё нет', function (): void {
    // Аккаунт заведён вручную (сидом), профиль не создан.
    $user = User::factory()->create(['role' => Role::Sender]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/profile')
        ->assertOk()
        ->assertJsonPath('data.role', 'sender')
        ->assertJsonPath('data.profile', null);
});

it('требует токен для просмотра профиля', function (): void {
    $this->getJson('/api/v1/profile')
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('отвечает 401 и без заголовка Accept', function (): void {
    // Клиент без Accept: application/json не должен получать редирект на вход.
    $this->get('/api/v1/profile')
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});
