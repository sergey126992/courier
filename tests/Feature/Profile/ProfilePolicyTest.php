<?php

declare(strict_types=1);

use App\Domain\Identity\Role;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

it('не раскрывает чужой профиль: 404 вместо 403', function (): void {
    $owner = User::factory()->sender()->create(['name' => 'Владелец']);
    $stranger = User::factory()->sender()->create(['name' => 'Посторонний']);

    Sanctum::actingAs($stranger);

    $foreign = $this->getJson('/api/v1/users/'.$owner->id);

    $foreign->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'NOT_FOUND');

    // Ответ на существующий чужой id неотличим от ответа на несуществующий:
    // отличается только путь запроса в поле instance.
    $missing = $this->getJson('/api/v1/users/'.($owner->id + 1000));

    $missing->assertStatus(404);

    $withoutInstance = static function (array $body): array {
        unset($body['instance']);

        return $body;
    };

    expect($withoutInstance((array) $foreign->json()))
        ->toBe($withoutInstance((array) $missing->json()));
});

it('отдаёт свой профиль по идентификатору', function (): void {
    $user = User::factory()->sender()->create();

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/users/'.$user->id)
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.role', 'sender');
});

it('разрешает смотреть и менять только свой профиль', function (): void {
    $sender = User::factory()->sender()->create();
    $other = User::factory()->sender()->create();

    expect(Gate::forUser($sender)->allows('viewProfile', $sender))->toBeTrue()
        ->and(Gate::forUser($sender)->allows('viewProfile', $other))->toBeFalse()
        ->and(Gate::forUser($sender)->allows('updateProfile', $sender))->toBeTrue()
        ->and(Gate::forUser($sender)->allows('updateProfile', $other))->toBeFalse();
});

it('опирается на правила ролей из домена', function (Role $role, bool $canEdit): void {
    $user = User::factory()->create(['role' => $role]);

    expect(Gate::forUser($user)->allows('viewProfile', $user))->toBeTrue()
        ->and(Gate::forUser($user)->allows('updateProfile', $user))->toBe($canEdit);
})->with([
    'отправитель' => [Role::Sender, true],
    'курьер' => [Role::Courier, true],
    'диспетчер' => [Role::Dispatcher, false],
    'администратор' => [Role::Admin, false],
]);
