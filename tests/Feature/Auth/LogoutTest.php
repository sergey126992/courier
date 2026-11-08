<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;

it('отзывает текущий токен и отвечает без тела', function (): void {
    $user = User::factory()->sender()->create();

    $current = $user->createToken('api-v1', $user->role->abilities())->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$current)
        ->postJson('/api/v1/auth/logout')
        ->assertNoContent();

    // Токен удалён из хранилища.
    $this->assertDatabaseCount('personal_access_tokens', 0);

    // В тестах несколько запросов делят контейнер, поэтому сбрасываем кэш guard,
    // чтобы следующий запрос аутентифицировался заново — как в реальном HTTP-запросе.
    Auth::forgetGuards();

    // Отозванный токен больше не принимается.
    $this->withHeader('Authorization', 'Bearer '.$current)
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('не отзывает токены других устройств', function (): void {
    $user = User::factory()->sender()->create();

    $current = $user->createToken('api-v1', $user->role->abilities())->plainTextToken;
    $other = $user->createToken('api-v1', $user->role->abilities())->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$current)
        ->postJson('/api/v1/auth/logout')
        ->assertNoContent();

    // Второй токен того же пользователя остаётся рабочим.
    Auth::forgetGuards();

    $this->withHeader('Authorization', 'Bearer '.$other)
        ->postJson('/api/v1/auth/email/resend')
        ->assertStatus(202);

    expect($user->tokens()->count())->toBe(1);
});

it('требует авторизации для выхода', function (): void {
    $this->postJson('/api/v1/auth/logout')
        ->assertStatus(401)
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});
