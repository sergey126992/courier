<?php

declare(strict_types=1);

use App\Livewire\Auth\LoginForm;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Форма входа (ЧТЗ 9.2, пункты 1–2)
|--------------------------------------------------------------------------
|
| FR-UI-102, 104, 109: проверка учётных данных одна, ошибка у поля не
| раскрывает существование аккаунта, превышение лимита не аутентифицирует,
| успешный вход отправляет на домашнюю страницу роли без токена API.
|
*/

/**
 * Регистрирует домашние страницы ролей: сами экраны появятся в следующих
 * пунктах плана, а редиректу нужны реальные имена маршрутов из ЧТЗ 8.1.
 */
function registerLoginFormHomeRoutes(): void
{
    Route::middleware('web')->group(function (): void {
        Route::get('/cabinet/orders', static fn (): string => '')->name('cabinet.orders');
        Route::get('/dispatcher', static fn (): string => '')->name('dispatcher.queue');
        Route::get('/account', static fn (): string => '')->name('account');
    });

    // Маршруты добавлены после загрузки приложения: обновляем поиск по именам.
    Route::getRoutes()->refreshNameLookups();
}

it('показывает ошибку у поля email при неверном пароле', function (): void {
    User::factory()->create([
        'email' => 'login@example.com',
        'password' => 'secret-pass-1',
    ]);

    $component = Livewire::test(LoginForm::class)
        ->set('email', 'login@example.com')
        ->set('password', 'wrong-pass-1')
        ->call('login');

    expect($component->errors()->first('email'))->toBe(__('auth.failed'))
        ->and(Auth::check())->toBeFalse();
});

it('не раскрывает существование аккаунта тем же сообщением', function (): void {
    User::factory()->create([
        'email' => 'known@example.com',
        'password' => 'secret-pass-1',
    ]);

    $known = Livewire::test(LoginForm::class)
        ->set('email', 'known@example.com')
        ->set('password', 'wrong-pass-1')
        ->call('login');

    $unknown = Livewire::test(LoginForm::class)
        ->set('email', 'nobody@example.com')
        ->set('password', 'wrong-pass-1')
        ->call('login');

    expect($known->errors()->first('email'))
        ->toBe($unknown->errors()->first('email'))
        ->toBe(__('auth.failed'));
});

it('после верного входа отправляет отправителя в кабинет', function (): void {
    registerLoginFormHomeRoutes();

    $sender = User::factory()->sender()->create([
        'email' => 'sender@example.com',
        'password' => 'secret-pass-1',
    ]);

    Livewire::test(LoginForm::class)
        ->set('email', 'Sender@Example.com')
        ->set('password', 'secret-pass-1')
        ->call('login')
        ->assertRedirect(route('cabinet.orders'));

    expect(Auth::id())->toBe($sender->id)
        ->and($sender->tokens()->count())->toBe(0);
});

it('отправляет диспетчера в очередь заказов', function (): void {
    registerLoginFormHomeRoutes();

    User::factory()->dispatcher()->create([
        'email' => 'dispatcher@example.com',
        'password' => 'secret-pass-1',
    ]);

    Livewire::test(LoginForm::class)
        ->set('email', 'dispatcher@example.com')
        ->set('password', 'secret-pass-1')
        ->call('login')
        ->assertRedirect(route('dispatcher.queue'));
});

it('при превышении лимита показывает сообщение и не аутентифицирует', function (): void {
    config(['rate_limits.login.attempts' => 1]);

    User::factory()->create([
        'email' => 'limit@example.com',
        'password' => 'secret-pass-1',
    ]);

    Livewire::test(LoginForm::class)
        ->set('email', 'limit@example.com')
        ->set('password', 'wrong-pass-1')
        ->call('login');

    $component = Livewire::test(LoginForm::class)
        ->set('email', 'limit@example.com')
        ->set('password', 'secret-pass-1')
        ->call('login');

    expect($component->errors()->first('email'))
        ->toContain('Слишком много попыток')
        ->and(Auth::check())->toBeFalse();
});

it('требует email и пароль', function (): void {
    Livewire::test(LoginForm::class)
        ->set('email', '')
        ->set('password', '')
        ->call('login')
        ->assertHasErrors(['email' => 'required', 'password' => 'required']);
});
