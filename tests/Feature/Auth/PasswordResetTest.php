<?php

declare(strict_types=1);

use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Подписанная ссылка сброса, как в письме.
 */
function passwordResetLinkFor(User $user, string $token, int $minutes = 60): string
{
    return URL::temporarySignedRoute('auth.password.reset', now()->addMinutes($minutes), [
        'token' => $token,
        'email' => (string) $user->email,
    ]);
}

/**
 * Запрашивает сброс и возвращает ссылку из поставленного в очередь письма.
 */
function requestResetLink(string $email): string
{
    $link = '';

    Mail::assertQueued(ResetPasswordMail::class, function (ResetPasswordMail $mail) use (&$link): bool {
        $link = $mail->resetUrl();

        return true;
    });

    return $link;
}

it('отвечает 202 одинаково для существующего и несуществующего email', function (): void {
    Mail::fake();

    $user = User::factory()->sender()->create(['email' => 'reset@example.com']);

    $existing = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'Reset@Example.com']);
    $unknown = $this->postJson('/api/v1/auth/password/forgot', ['email' => 'nobody@example.com']);

    $existing->assertStatus(202);
    $unknown->assertStatus(202);

    // Тело ответа не различает существующий и несуществующий адрес.
    expect($existing->getContent())->toBe($unknown->getContent());

    Mail::assertQueued(ResetPasswordMail::class, 1);
    Mail::assertQueued(
        ResetPasswordMail::class,
        static fn (ResetPasswordMail $mail): bool => $mail->hasTo((string) $user->email),
    );
    $this->assertDatabaseCount('password_reset_tokens', 1);
});

it('требует корректный email в запросе сброса', function (): void {
    Mail::fake();

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'не-адрес'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    Mail::assertNothingQueued();
});

it('меняет пароль и отзывает все токены по валидной ссылке', function (): void {
    Mail::fake();

    $user = User::factory()->sender()->create([
        'email' => 'reset@example.com',
        'password' => 'old-pass-1',
    ]);
    $token = $user->createToken('api-v1', $user->role->abilities())->plainTextToken;

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'reset@example.com'])->assertStatus(202);

    $link = requestResetLink('reset@example.com');

    $this->postJson($link, [
        'password' => 'new-pass-1',
        'password_confirmation' => 'new-pass-1',
    ])
        ->assertOk()
        ->assertJsonPath('message', __('auth.password_reset'));

    $refreshed = $user->fresh();

    expect(Hash::check('new-pass-1', (string) $refreshed?->password))->toBeTrue()
        ->and(Hash::check('old-pass-1', (string) $refreshed?->password))->toBeFalse();

    // Все прежние токены отозваны: старый токен больше не принимается.
    $this->assertDatabaseCount('personal_access_tokens', 0);

    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/api/v1/auth/logout')
        ->assertStatus(401)
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');

    // Ссылка одноразовая: повторный запрос по той же ссылке отклоняется.
    $this->postJson($link, [
        'password' => 'other-pass-1',
        'password_confirmation' => 'other-pass-1',
    ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'INVALID_SIGNATURE');

    expect(Hash::check('new-pass-1', (string) $user->fresh()?->password))->toBeTrue();
});

it('отклоняет просроченную ссылку сброса', function (): void {
    Mail::fake();

    $user = User::factory()->sender()->create([
        'email' => 'expired@example.com',
        'password' => 'old-pass-1',
    ]);

    $this->postJson('/api/v1/auth/password/forgot', ['email' => 'expired@example.com'])->assertStatus(202);

    $link = requestResetLink('expired@example.com');

    $this->travel(61)->minutes();

    $this->postJson($link, [
        'password' => 'new-pass-1',
        'password_confirmation' => 'new-pass-1',
    ])
        ->assertStatus(403)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'INVALID_SIGNATURE');

    expect(Hash::check('old-pass-1', (string) $user->fresh()?->password))->toBeTrue();
});

it('отклоняет ссылку с неизвестным токеном', function (): void {
    Mail::fake();

    $user = User::factory()->sender()->create(['email' => 'tampered@example.com']);

    $link = passwordResetLinkFor($user, 'поддельный-токен');

    $this->postJson($link, [
        'password' => 'new-pass-1',
        'password_confirmation' => 'new-pass-1',
    ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'INVALID_SIGNATURE');
});

it('требует совпадения пароля и подтверждения при сбросе', function (): void {
    Mail::fake();

    $user = User::factory()->sender()->create(['email' => 'confirm@example.com']);

    $link = passwordResetLinkFor($user, 'some-token');

    $this->postJson($link, [
        'password' => 'new-pass-1',
        'password_confirmation' => 'other-pass-1',
    ])
        ->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');
});
