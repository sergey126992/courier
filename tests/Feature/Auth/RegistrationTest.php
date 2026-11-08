<?php

declare(strict_types=1);

use App\Domain\Identity\Role;
use App\Mail\VerifyEmailMail;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Данные регистрации по умолчанию; переопределяются в конкретном тесте.
 *
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function registerPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Иван Отправитель',
        'email' => 'ivan@example.com',
        'password' => 'secret-pass-1',
        'password_confirmation' => 'secret-pass-1',
    ], $overrides);
}

/**
 * Возвращает поля, по которым валидация вернула ошибку.
 *
 * @param TestResponse<Response> $response
 * @return list<string>
 */
function validationErrorFields(TestResponse $response): array
{
    $details = (array) $response->json('details');

    return array_values(array_map(static fn (array $item): string => (string) $item['field'], $details));
}

it('регистрирует отправителя и ставит письмо в очередь', function (): void {
    Mail::fake();

    $response = $this->postJson('/api/v1/auth/register', registerPayload([
        'email' => 'Ivan@Example.COM',
    ]));

    $response->assertCreated()
        ->assertHeader('Location', url('/api/v1/profile'))
        ->assertJsonPath('data.email', 'ivan@example.com')
        ->assertJsonPath('data.role', Role::Sender->value);

    expect(array_keys((array) $response->json('data')))->not->toContain('password');

    $user = User::query()->where('email', 'ivan@example.com')->sole();

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'email' => 'ivan@example.com',
        'role' => Role::Sender->value,
        'email_verified_at' => null,
    ]);
    $this->assertDatabaseHas('sender_profiles', ['user_id' => $user->id]);

    Mail::assertQueued(VerifyEmailMail::class, fn (VerifyEmailMail $mail): bool => $mail->hasTo('ivan@example.com'));
});

it('отклоняет дубликат email в другом регистре', function (): void {
    User::factory()->create(['email' => 'ivan@example.com']);

    $response = $this->postJson('/api/v1/auth/register', registerPayload([
        'email' => 'IVAN@EXAMPLE.COM',
    ]));

    $response->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    expect(validationErrorFields($response))->toContain('email');
});

it('отклоняет короткий пароль', function (): void {
    $response = $this->postJson('/api/v1/auth/register', registerPayload([
        'password' => 'short',
        'password_confirmation' => 'short',
    ]));

    $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');

    expect(validationErrorFields($response))->toContain('password');
});

it('отклоняет несовпадение подтверждения пароля', function (): void {
    $response = $this->postJson('/api/v1/auth/register', registerPayload([
        'password_confirmation' => 'other-pass-1',
    ]));

    $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');

    expect(validationErrorFields($response))->toContain('password');
});

it('не допускает дубликат email в другом регистре на уровне базы', function (): void {
    User::factory()->create(['email' => 'case@example.com']);

    expect(static fn () => DB::table('users')->insert([
        'name' => 'Дубликат',
        'email' => 'CASE@example.com',
        'password' => 'secret',
        'role' => Role::Sender->value,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
