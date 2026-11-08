<?php

declare(strict_types=1);

use App\Models\User;

/**
 * Данные регистрации для проверки лимита.
 *
 * @return array<string, string>
 */
function registrationPayload(string $email): array
{
    return [
        'name' => 'Иван Отправитель',
        'email' => $email,
        'password' => 'secret-pass-1',
        'password_confirmation' => 'secret-pass-1',
    ];
}

it('ограничивает неудачные попытки входа и отдаёт заголовок повтора', function (): void {
    $email = 'limit@example.com';
    $attempts = (int) config('rate_limits.login.attempts');

    for ($i = 0; $i < $attempts; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => $email,
            'password' => 'wrong-pass-1',
        ])->assertStatus(401);
    }

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $email,
        'password' => 'wrong-pass-1',
    ]);

    $response->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'RATE_LIMITED');

    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
});

it('не расходует лимит успешными входами', function (): void {
    User::factory()->create([
        'email' => 'success@example.com',
        'password' => 'secret-pass-1',
    ]);

    // Успешных входов больше, чем предел неудачных попыток.
    for ($i = 0; $i <= (int) config('rate_limits.login.attempts'); $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'success@example.com',
            'password' => 'secret-pass-1',
        ])->assertOk();
    }
});

it('сбрасывает счётчик неудач после успешного входа', function (): void {
    User::factory()->create([
        'email' => 'recover@example.com',
        'password' => 'secret-pass-1',
    ]);

    for ($i = 0; $i < 4; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'recover@example.com',
            'password' => 'wrong-pass-1',
        ])->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'recover@example.com',
        'password' => 'secret-pass-1',
    ])->assertOk();

    // После успешного входа счётчик обнулён — следующая ошибка снова 401, а не 429.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'recover@example.com',
        'password' => 'wrong-pass-1',
    ])->assertStatus(401);
});

it('не блокирует вход других адресов при исчерпанном лимите', function (): void {
    $attempts = (int) config('rate_limits.login.attempts');

    for ($i = 0; $i < $attempts; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'blocked@example.com',
            'password' => 'wrong-pass-1',
        ])->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'blocked@example.com',
        'password' => 'wrong-pass-1',
    ])->assertStatus(429);

    // Ключ лимита включает email, поэтому другой адрес не затронут.
    $this->postJson('/api/v1/auth/login', [
        'email' => 'other@example.com',
        'password' => 'wrong-pass-1',
    ])->assertStatus(401);
});

it('читает лимит входа из конфигурации', function (): void {
    config(['rate_limits.login.attempts' => 2]);

    for ($i = 0; $i < 2; $i++) {
        $this->postJson('/api/v1/auth/login', [
            'email' => 'config@example.com',
            'password' => 'wrong-pass-1',
        ])->assertStatus(401);
    }

    $this->postJson('/api/v1/auth/login', [
        'email' => 'config@example.com',
        'password' => 'wrong-pass-1',
    ])->assertStatus(429)
        ->assertHeader('Retry-After');
});

it('ограничивает регистрацию и отдаёт заголовок повтора', function (): void {
    config(['rate_limits.register.attempts' => 2]);

    $this->postJson('/api/v1/auth/register', registrationPayload('first@example.com'))->assertCreated();
    $this->postJson('/api/v1/auth/register', registrationPayload('second@example.com'))->assertCreated();

    $response = $this->postJson('/api/v1/auth/register', registrationPayload('third@example.com'));

    $response->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'RATE_LIMITED');

    $this->assertDatabaseMissing('users', ['email' => 'third@example.com']);
});

it('ограничивает запросы сброса пароля', function (): void {
    config(['rate_limits.password_forgot.attempts' => 2]);

    User::factory()->create(['email' => 'forgot@example.com']);

    for ($i = 0; $i < 2; $i++) {
        $this->postJson('/api/v1/auth/password/forgot', ['email' => 'forgot@example.com'])
            ->assertStatus(202);
    }

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'forgot@example.com'])
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'RATE_LIMITED');
});

it('ограничивает повторную отправку письма подтверждения по пользователю', function (): void {
    config(['rate_limits.email_resend.attempts' => 1]);

    $user = User::factory()->unverified()->create();
    $token = $user->createToken('api-v1', $user->role->abilities())->plainTextToken;

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/auth/email/resend')
        ->assertStatus(202);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/auth/email/resend')
        ->assertStatus(429)
        ->assertJsonPath('code', 'RATE_LIMITED');
});
