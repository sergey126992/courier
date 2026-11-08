<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Главная страница (ЧТЗ 9.1, пункты 3–5; FR-UI-117, 8.4)
|--------------------------------------------------------------------------
|
| Гость видит лендинг с входом и регистрацией, авторизованный уходит в
| домашний раздел своей роли из config('ui.roles'), а не на страницу входа.
|
*/

it('регистрирует маршрут home для главной страницы', function (): void {
    expect(Route::has('home'))->toBeTrue()
        ->and(route('home'))->toBe(url('/'));
});

it('показывает гостю лендинг с входом и регистрацией', function (): void {
    $response = $this->get('/');

    $response->assertOk()
        ->assertSee(__('ui.landing.title'))
        ->assertSee(__('ui.landing.description'))
        ->assertSee(__('ui.landing.login'))
        ->assertSee(__('ui.landing.register'))
        ->assertSee(route('login'), false)
        ->assertSee(route('register'), false);
});

it('уводит авторизованного в домашний раздел его роли', function (): void {
    Route::middleware(['web', 'auth'])->get('/cabinet/orders', static fn (): string => '')->name('cabinet.orders');
    Route::middleware(['web', 'auth'])->get('/dispatcher', static fn (): string => '')->name('dispatcher.queue');
    Route::getRoutes()->refreshNameLookups();

    $users = [
        'sender' => User::factory()->sender()->create(),
        'courier' => User::factory()->courier()->create(),
        'dispatcher' => User::factory()->dispatcher()->create(),
        'admin' => User::factory()->admin()->create(),
    ];

    foreach ($users as $role => $user) {
        $home = (string) config('ui.roles.'.$role.'.home');

        $this->actingAs($user)->get('/')->assertRedirect(route($home));
    }
});

it('не показывает гостю действие выхода', function (): void {
    $this->get('/')->assertOk()->assertDontSee(__('ui.nav.logout'));
});
