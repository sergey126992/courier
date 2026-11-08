<?php

declare(strict_types=1);

use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/*
|--------------------------------------------------------------------------
| Восстановление пароля в браузере (ЧТЗ 9.1, пункты 9, 10, 15)
|--------------------------------------------------------------------------
|
| FR-UI-107: запрос не раскрывает существование аккаунта, ссылка открывает
| HTML-форму, сброс меняет пароль, отзывает токены API и уводит на `login`
| с сообщением, испорченная ссылка даёт 403.
|
*/

it('отдаёт гостю форму запроса ссылки', function (): void {
    $this->get('/forgot-password')
        ->assertOk()
        ->assertSee(__('ui.forms.forgot_password_title'))
        ->assertSee(__('ui.forms.submit_forgot_password'));
});

it('отвечает одинаково для существующего и несуществующего адреса', function (): void {
    Mail::fake();

    User::factory()->sender()->create(['email' => 'exists@example.com']);

    $existing = $this->post('/forgot-password', ['email' => 'exists@example.com']);
    $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com']);

    $existing->assertRedirect(route('password.request'))
        ->assertSessionHas('status', __('auth.password_reset_sent'));

    $unknown->assertRedirect(route('password.request'))
        ->assertSessionHas('status', __('auth.password_reset_sent'));

    Mail::assertQueued(ResetPasswordMail::class, 1);
});

it('открывает форму сброса по ссылке из письма и меняет пароль', function (): void {
    Mail::fake();

    $user = User::factory()->sender()->create([
        'email' => 'reset@example.com',
        'password' => 'old-pass-1',
    ]);
    $user->createToken('api-v1', $user->role->abilities());

    $this->post('/forgot-password', ['email' => 'reset@example.com']);

    $link = '';
    Mail::assertQueued(ResetPasswordMail::class, function (ResetPasswordMail $mail) use (&$link): bool {
        $link = $mail->resetUrl();

        return true;
    });

    $this->get($link)
        ->assertOk()
        ->assertSee(__('ui.forms.reset_password_title'));

    $this->post($link, [
        'password' => 'new-pass-1',
        'password_confirmation' => 'new-pass-1',
    ])
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', __('auth.password_reset'));

    $refreshed = $user->fresh();

    expect(Hash::check('new-pass-1', (string) $refreshed?->password))->toBeTrue()
        ->and(Hash::check('old-pass-1', (string) $refreshed?->password))->toBeFalse();

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('отдаёт страницу 403 по просроченной ссылке сброса', function (): void {
    $user = User::factory()->sender()->create(['email' => 'expired@example.com']);

    $link = URL::temporarySignedRoute('password.reset', now()->subMinute(), [
        'token' => 'token',
        'email' => 'expired@example.com',
    ]);

    $this->get($link)->assertStatus(403);

    expect($user->fresh()?->password)->not->toBeNull();
});

it('отклоняет подписанную ссылку с неизвестным токеном', function (): void {
    User::factory()->sender()->create(['email' => 'tampered@example.com', 'password' => 'old-pass-1']);

    $link = URL::temporarySignedRoute('password.reset', now()->addMinutes(60), [
        'token' => 'поддельный-токен',
        'email' => 'tampered@example.com',
    ]);

    $this->post($link, [
        'password' => 'new-pass-1',
        'password_confirmation' => 'new-pass-1',
    ])->assertStatus(403);
});
