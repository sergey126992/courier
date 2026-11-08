<?php

declare(strict_types=1);

use App\Mail\VerifyEmailMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

/*
|--------------------------------------------------------------------------
| Регистрация и подтверждение почты в браузере (ЧТЗ 9.1, пункты 7, 8, 15)
|--------------------------------------------------------------------------
|
| FR-UI-105, FR-UI-106: гость регистрируется через web-форму, ссылка из
| письма открывается как HTML-страница, повтор и гость без сессии не
| ошибка, испорченная подпись даёт 403, неподтверждённый пользователь
| уходит на `verification.notice`.
|
*/

it('отдаёт гостю форму регистрации', function (): void {
    $this->get('/register')
        ->assertOk()
        ->assertSee(__('ui.forms.register_title'))
        ->assertSee(__('ui.forms.submit_register'));
});

it('регистрирует через POST, открывает сессию и ставит письмо в очередь', function (): void {
    Mail::fake();

    $this->post('/register', [
        'name' => 'Иван Отправитель',
        'email' => 'Web@Example.com',
        'password' => 'secret-pass-1',
        'password_confirmation' => 'secret-pass-1',
    ])->assertRedirect(route('verification.notice'));

    $user = User::query()->where('email', 'web@example.com')->sole();

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('sender_profiles', ['user_id' => $user->id]);

    Mail::assertQueued(VerifyEmailMail::class, fn (VerifyEmailMail $mail): bool => $mail->hasTo('web@example.com'));
});

it('открывает ссылку подтверждения в браузере и не падает при повторе', function (): void {
    Mail::fake();

    $this->post('/register', [
        'name' => 'Иван Отправитель',
        'email' => 'browser@example.com',
        'password' => 'secret-pass-1',
        'password_confirmation' => 'secret-pass-1',
    ])->assertRedirect(route('verification.notice'));

    $link = '';
    Mail::assertQueued(VerifyEmailMail::class, function (VerifyEmailMail $mail) use (&$link): bool {
        $link = $mail->verificationUrl();

        return true;
    });

    $this->get($link)
        ->assertOk()
        ->assertSee(__('ui.verification.verified_title'));

    expect(User::query()->sole()->email_verified_at)->not->toBeNull();

    $this->get($link)
        ->assertOk()
        ->assertSee(__('ui.verification.already_verified_title'));
});

it('подтверждает почту гостю без входа', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'guest@example.com']);

    $link = URL::temporarySignedRoute('verification.verify.web', now()->addMinutes(60), [
        'id' => $user->getKey(),
        'hash' => sha1('guest@example.com'),
    ]);

    $this->get($link)
        ->assertOk()
        ->assertSee(__('ui.verification.verified_title'));

    expect($user->fresh()?->email_verified_at)->not->toBeNull();
});

it('отдаёт HTML-страницу 403 при испорченной подписи', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'expired@example.com']);

    $link = URL::temporarySignedRoute('verification.verify.web', now()->subMinute(), [
        'id' => $user->getKey(),
        'hash' => sha1('expired@example.com'),
    ]);

    $response = $this->get($link);

    $response->assertStatus(403);
    expect($response->headers->get('Content-Type'))->toStartWith('text/html');
    expect($user->fresh()?->email_verified_at)->toBeNull();
});

it('отклоняет ссылку с подделанным хешем', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'hash@example.com']);

    $link = URL::temporarySignedRoute('verification.verify.web', now()->addMinutes(60), [
        'id' => $user->getKey(),
        'hash' => sha1('other@example.com'),
    ]);

    $this->get($link)->assertStatus(403);

    expect($user->fresh()?->email_verified_at)->toBeNull();
});

it('уводит неподтверждённого пользователя на напоминание', function (): void {
    Route::middleware(['web', 'auth', 'verified'])
        ->get('/cabinet/orders', static fn (): string => '')
        ->name('cabinet.orders');

    Route::getRoutes()->refreshNameLookups();

    $user = User::factory()->unverified()->sender()->create();

    $this->actingAs($user)->get('/cabinet/orders')->assertRedirect(route('verification.notice'));

    $user->markEmailAsVerified();

    $this->actingAs($user)->get('/cabinet/orders')->assertOk();
});

it('показывает напоминание и отправляет письмо повторно', function (): void {
    Mail::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)->get('/email/verify')
        ->assertOk()
        ->assertSee(__('ui.verification.notice'));

    $this->actingAs($user)->post(route('verification.send'))
        ->assertRedirect()
        ->assertSessionHas('status', __('auth.verification_sent'));

    Mail::assertQueued(VerifyEmailMail::class, fn (VerifyEmailMail $mail): bool => $mail->hasTo((string) $user->email));
});

it('закрывает напоминание гостю', function (): void {
    $this->get('/email/verify')->assertRedirect('/login');
});
