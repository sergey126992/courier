<?php

declare(strict_types=1);

use App\Livewire\Auth\LoginForm;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Редиректы входа и выход (ЧТЗ 9.1, пункты 1, 2, 11, 12; 8.4)
|--------------------------------------------------------------------------
|
| FR-UI-103, 108, 111: гость получает 302 на `login`, после входа
| возвращается на запомненный адрес, веб-вход не создаёт токен API,
| повторный выход без сессии уходит на страницу входа.
|
*/

/**
 * Регистрирует защищённую страницу: сам кабинет появится в следующих пунктах
 * плана, а проверке редиректа нужен реальный маршрут с middleware `auth`.
 */
function registerLoginRedirectRoutes(): void
{
    Route::middleware(['web', 'auth'])
        ->get('/cabinet/orders', static fn (): string => '')
        ->name('cabinet.orders');

    Route::getRoutes()->refreshNameLookups();
}

it('перенаправляет гостя на страницу входа вместо исключения', function (): void {
    registerLoginRedirectRoutes();

    $this->get('/cabinet/orders')->assertRedirect('/login');
});

it('регистрирует маршруты входа и выхода с именами из ЧТЗ 8.1', function (): void {
    expect(Route::has('login'))->toBeTrue()
        ->and(Route::has('login.store'))->toBeTrue()
        ->and(Route::has('logout'))->toBeTrue();
});

it('отдаёт гостю форму входа', function (): void {
    $this->get('/login')
        ->assertOk()
        ->assertSee(__('ui.forms.login_title'))
        ->assertSee(__('ui.forms.submit_login'));
});

it('возвращает на запомненный адрес после входа', function (): void {
    registerLoginRedirectRoutes();

    User::factory()->sender()->create([
        'email' => 'intended@example.com',
        'password' => 'secret-pass-1',
    ]);

    $this->get('/cabinet/orders')->assertRedirect('/login');

    Livewire::test(LoginForm::class)
        ->set('email', 'intended@example.com')
        ->set('password', 'secret-pass-1')
        ->call('login')
        ->assertRedirect(route('cabinet.orders'));
});

it('не создаёт токен API при веб-входе', function (): void {
    registerLoginRedirectRoutes();

    $user = User::factory()->sender()->create([
        'email' => 'weblogin@example.com',
        'password' => 'secret-pass-1',
    ]);

    Livewire::test(LoginForm::class)
        ->set('email', 'weblogin@example.com')
        ->set('password', 'secret-pass-1')
        ->call('login');

    expect(Auth::id())->toBe($user->id);

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('открывает сессию через POST login без Livewire', function (): void {
    registerLoginRedirectRoutes();

    $user = User::factory()->sender()->create([
        'email' => 'post@example.com',
        'password' => 'secret-pass-1',
    ]);

    $this->post('/login', [
        'email' => 'post@example.com',
        'password' => 'secret-pass-1',
    ])->assertRedirect(route('cabinet.orders'));

    expect(Auth::id())->toBe($user->id);

    $this->assertDatabaseCount('personal_access_tokens', 0);
});

it('показывает ошибку у поля при неверном POST login', function (): void {
    User::factory()->create([
        'email' => 'post-fail@example.com',
        'password' => 'secret-pass-1',
    ]);

    $this->from('/login')
        ->post('/login', [
            'email' => 'post-fail@example.com',
            'password' => 'wrong-pass-1',
        ])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['email']);

    expect(Auth::check())->toBeFalse();
});

it('завершает сессию и отправляет на главную страницу', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/logout')->assertRedirect('/');

    expect(Auth::check())->toBeFalse();
});

it('повторный выход без сессии перенаправляет на страницу входа', function (): void {
    $this->post('/logout')->assertRedirect('/login');
});
