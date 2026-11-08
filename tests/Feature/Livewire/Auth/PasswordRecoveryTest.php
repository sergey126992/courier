<?php

declare(strict_types=1);

use App\Livewire\Auth\ForgotPasswordForm;
use App\Livewire\Auth\ResetPasswordForm;
use App\Mail\ResetPasswordMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Восстановление пароля в браузере (ЧТЗ 9.2, пункты 3–4; 9.1, пункт 9)
|--------------------------------------------------------------------------
|
| FR-UI-107: ответ на запрос не зависит от существования аккаунта, письмо
| ведёт на web-маршрут, сброс меняет пароль, отзывает токены API и
| возвращает на страницу входа с сообщением.
|
*/

it('отвечает одинаково для существующего и несуществующего адреса', function (): void {
    Mail::fake();

    User::factory()->sender()->create(['email' => 'exists@example.com']);

    $existing = Livewire::test(ForgotPasswordForm::class)
        ->set('email', 'Exists@Example.com')
        ->call('send');

    $unknown = Livewire::test(ForgotPasswordForm::class)
        ->set('email', 'nobody@example.com')
        ->call('send');

    expect($existing->get('sent'))->toBeTrue()
        ->and($unknown->get('sent'))->toBeTrue();

    $existing->assertSee(__('auth.password_reset_sent'));
    $unknown->assertSee(__('auth.password_reset_sent'));

    Mail::assertQueued(ResetPasswordMail::class, 1);
});

it('строит ссылку письма на web-маршрут сброса', function (): void {
    Mail::fake();

    User::factory()->sender()->create(['email' => 'link@example.com']);

    Livewire::test(ForgotPasswordForm::class)
        ->set('email', 'link@example.com')
        ->call('send');

    Mail::assertQueued(ResetPasswordMail::class, function (ResetPasswordMail $mail): bool {
        return str_starts_with($mail->resetUrl(), route('password.reset', ['token' => $mail->token]));
    });
});

it('меняет пароль и отзывает прежние токены API', function (): void {
    $user = User::factory()->sender()->create([
        'email' => 'reset@example.com',
        'password' => 'old-pass-1',
    ]);
    $user->createToken('api-v1', $user->role->abilities());

    $token = Password::broker()->createToken($user);

    Livewire::test(ResetPasswordForm::class, ['token' => $token, 'email' => 'reset@example.com'])
        ->set('password', 'new-pass-1')
        ->set('password_confirmation', 'new-pass-1')
        ->call('reset')
        ->assertHasNoErrors()
        ->assertRedirect(route('login'));

    $refreshed = $user->fresh();

    expect(Hash::check('new-pass-1', (string) $refreshed?->password))->toBeTrue()
        ->and(Hash::check('old-pass-1', (string) $refreshed?->password))->toBeFalse();

    $this->assertDatabaseCount('personal_access_tokens', 0);

    expect(session('status'))->toBe(__('auth.password_reset'));
});

it('показывает ошибку при уже использованной или подделанной ссылке', function (): void {
    $user = User::factory()->sender()->create([
        'email' => 'used@example.com',
        'password' => 'old-pass-1',
    ]);

    $component = Livewire::test(ResetPasswordForm::class, [
        'token' => 'уже-использованная-ссылка',
        'email' => 'used@example.com',
    ])
        ->set('password', 'new-pass-1')
        ->set('password_confirmation', 'new-pass-1')
        ->call('reset')
        ->assertHasErrors(['email']);

    expect($component->errors()->first('email'))->toBe(__('errors.codes.INVALID_SIGNATURE.detail'))
        ->and(Hash::check('old-pass-1', (string) $user->fresh()?->password))->toBeTrue();
});

it('требует совпадения пароля и подтверждения', function (): void {
    Livewire::test(ResetPasswordForm::class, ['token' => 'token', 'email' => 'user@example.com'])
        ->set('password', 'new-pass-1')
        ->set('password_confirmation', 'other-pass-1')
        ->call('reset')
        ->assertHasErrors(['password']);
});
