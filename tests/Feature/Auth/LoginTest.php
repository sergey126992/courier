<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Mail;

it('выдаёт токен с разрешениями роли по верным данным', function (): void {
    Mail::fake();

    $user = User::factory()->sender()->create([
        'email' => 'login@example.com',
        'password' => 'secret-pass-1',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'Login@Example.com',
        'password' => 'secret-pass-1',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.email', 'login@example.com')
        ->assertJsonPath('data.role', 'sender');

    $token = (string) $response->json('token');

    expect($token)->not->toBe('')
        ->and(array_keys((array) $response->json('data')))->not->toContain('password');

    $accessToken = $user->tokens()->sole();

    expect($accessToken->abilities)->toBe(['orders:read', 'orders:write'])
        ->and($accessToken->name)->toBe('api-v1');

    // Выданный токен действительно открывает защищённый маршрут.
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/auth/email/resend')
        ->assertStatus(202);
});

it('отвечает одинаково на неверный пароль и несуществующий email', function (): void {
    User::factory()->create([
        'email' => 'known@example.com',
        'password' => 'secret-pass-1',
    ]);

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'email' => 'known@example.com',
        'password' => 'wrong-pass-1',
    ]);

    $unknownEmail = $this->postJson('/api/v1/auth/login', [
        'email' => 'nobody@example.com',
        'password' => 'wrong-pass-1',
    ]);

    foreach ([$wrongPassword, $unknownEmail] as $response) {
        $response->assertStatus(401)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'INVALID_CREDENTIALS');
    }

    // Причина отказа не раскрывается: тексты и структура совпадают.
    expect($wrongPassword->json('title'))->toBe($unknownEmail->json('title'))
        ->and($wrongPassword->json('detail'))->toBe($unknownEmail->json('detail'))
        ->and($wrongPassword->json('details'))->toBe($unknownEmail->json('details'));
});

it('не блокирует вход неподтверждённому пользователю', function (): void {
    User::factory()->unverified()->create([
        'email' => 'new@example.com',
        'password' => 'secret-pass-1',
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'new@example.com',
        'password' => 'secret-pass-1',
    ])
        ->assertOk()
        ->assertJsonPath('data.email_verified_at', null)
        ->assertJsonPath('data.email', 'new@example.com');
});

it('требует email и пароль', function (): void {
    $this->postJson('/api/v1/auth/login', [])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');
});

it('не возвращает пароль в ответе на вход', function (): void {
    User::factory()->create([
        'email' => 'hidden@example.com',
        'password' => 'secret-pass-1',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => 'hidden@example.com',
        'password' => 'secret-pass-1',
    ]);

    $body = (string) $response->getContent();

    expect($body)->not->toContain('secret-pass-1')
        ->and($body)->not->toContain('password=');
});
