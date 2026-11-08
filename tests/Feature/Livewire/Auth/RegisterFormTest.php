<?php

declare(strict_types=1);

use App\Domain\Identity\Role;
use App\Livewire\Auth\RegisterForm;
use App\Mail\VerifyEmailMail;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Форма регистрации (ЧТЗ 9.2, пункты 3–4)
|--------------------------------------------------------------------------
|
| FR-UI-105: регистрация создаёт аккаунт и профиль одной операцией, письмо
| уходит в очередь, сессия открыта, занятый email даёт ошибку у поля.
|
*/

it('регистрирует отправителя, открывает сессию и ставит письмо в очередь', function (): void {
    Mail::fake();

    Livewire::test(RegisterForm::class)
        ->set('name', 'Иван Отправитель')
        ->set('email', 'Ivan@Example.COM')
        ->set('password', 'secret-pass-1')
        ->set('password_confirmation', 'secret-pass-1')
        ->call('register')
        ->assertHasNoErrors()
        ->assertRedirect(route('verification.notice'));

    $user = User::query()->where('email', 'ivan@example.com')->sole();

    expect($user->role)->toBe(Role::Sender)
        ->and($user->email_verified_at)->toBeNull()
        ->and($user->senderProfile)->not->toBeNull()
        ->and(Auth::id())->toBe($user->id);

    // Ссылка письма ведёт на web-маршрут из config('ui.links.email_verification_route').
    Mail::assertQueued(VerifyEmailMail::class, function (VerifyEmailMail $mail) use ($user): bool {
        $expected = route('verification.verify.web', [
            'id' => $user->getKey(),
            'hash' => sha1('ivan@example.com'),
        ]);

        return $mail->hasTo('ivan@example.com')
            && str_starts_with($mail->verificationUrl(), $expected);
    });
});

it('показывает ошибку у поля при занятом email и не создаёт второго пользователя', function (): void {
    Mail::fake();

    User::factory()->create(['email' => 'taken@example.com']);

    $component = Livewire::test(RegisterForm::class)
        ->set('name', 'Иван Отправитель')
        ->set('email', 'TAKEN@Example.com')
        ->set('password', 'secret-pass-1')
        ->set('password_confirmation', 'secret-pass-1')
        ->call('register')
        ->assertHasErrors(['email']);

    expect($component->errors()->first('email'))->not->toBe('')
        ->and(Auth::check())->toBeFalse()
        ->and(User::query()->count())->toBe(1);

    Mail::assertNothingQueued();
});

it('требует имя, email и совпадающее подтверждение пароля', function (): void {
    Mail::fake();

    Livewire::test(RegisterForm::class)
        ->set('name', '')
        ->set('email', '')
        ->set('password', 'secret-pass-1')
        ->set('password_confirmation', 'other-pass-1')
        ->call('register')
        ->assertHasErrors(['name', 'email', 'password']);

    Mail::assertNothingQueued();
});
