<?php

declare(strict_types=1);

use App\Models\User;

/*
|--------------------------------------------------------------------------
| Доступ к панели диспетчера (ЧТЗ 5.5, пункт 12; раздел 8.1)
|--------------------------------------------------------------------------
|
| FR-UI-131: очередь открыта только диспетчеру; гость уходит на вход,
| отправитель, курьер и администратор получают 403, неподтверждённый
| диспетчер — напоминание о подтверждении почты.
|
*/

it('закрывает панель диспетчера гостю редиректом на вход', function (): void {
    $this->get('/dispatcher')->assertRedirect('/login');
});

it('показывает очередь диспетчеру', function (): void {
    $dispatcher = User::factory()->dispatcher()->create();

    $this->actingAs($dispatcher)->get('/dispatcher')
        ->assertOk()
        ->assertSee(__('dispatch.labels.queue_title'));
});

it('запрещает панель диспетчера отправителю, курьеру и администратору', function (): void {
    $users = [
        User::factory()->sender()->create(),
        User::factory()->courier()->create(),
        User::factory()->admin()->create(),
    ];

    foreach ($users as $user) {
        $this->actingAs($user)->get('/dispatcher')->assertForbidden();
    }
});

it('отправляет неподтверждённого диспетчера на подтверждение почты', function (): void {
    $dispatcher = User::factory()->unverified()->dispatcher()->create();

    $this->actingAs($dispatcher)->get('/dispatcher')
        ->assertRedirect(route('verification.notice'));
});
