<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\URL;

/**
 * Подписанная ссылка подтверждения, как в письме.
 */
function verificationLinkFor(User $user, ?string $hash = null): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->getKey(),
        'hash' => $hash ?? sha1((string) $user->email),
    ]);
}

it('подтверждает email по валидной ссылке', function (): void {
    $user = User::factory()->unverified()->create();

    $this->postJson(verificationLinkFor($user))
        ->assertOk()
        ->assertJsonPath('message', __('auth.email_verified'));

    expect($user->fresh()?->email_verified_at)->not->toBeNull();
});

it('не считает ошибкой повторное открытие ссылки', function (): void {
    $user = User::factory()->unverified()->create();
    $link = verificationLinkFor($user);

    $this->postJson($link)->assertOk();
    $verifiedAt = $user->fresh()?->email_verified_at;

    $this->postJson($link)->assertOk();

    expect($verifiedAt)->not->toBeNull()
        ->and((string) $user->fresh()?->email_verified_at)->toBe((string) $verifiedAt);
});

it('отклоняет истёкшую ссылку и не меняет дату', function (): void {
    $user = User::factory()->unverified()->create();
    $link = verificationLinkFor($user);

    $this->travel(61)->minutes();

    $response = $this->postJson($link);

    $response->assertStatus(403)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'INVALID_SIGNATURE');

    expect($user->fresh()?->email_verified_at)->toBeNull();
});

it('отклоняет подделанный хеш и не меняет дату', function (): void {
    $user = User::factory()->unverified()->create();

    $response = $this->postJson(verificationLinkFor($user, sha1('other@example.com')));

    $response->assertStatus(403)->assertJsonPath('code', 'INVALID_SIGNATURE');

    expect($user->fresh()?->email_verified_at)->toBeNull();
});
