<?php

declare(strict_types=1);

use App\Models\Order\DeliveryOrder;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Доступ к списку заказов отправителя (ЧТЗ 9.1, пункты 1, 3, 4, 7)
|--------------------------------------------------------------------------
|
| FR-UI-119, 124, 106: гость уходит на вход, диспетчер и курьер получают
| отказ, отправитель видит только свои заказы, неподтверждённый аккаунт —
| напоминание о подтверждении почты. Рендер списка — не более 3 запросов.
|
*/

it('закрывает список заказов гостю редиректом на вход', function (): void {
    $this->get('/cabinet/orders')->assertRedirect('/login');
});

it('запрещает список заказов диспетчеру, курьеру и администратору', function (): void {
    $users = [
        User::factory()->dispatcher()->create(),
        User::factory()->courier()->create(),
        User::factory()->admin()->create(),
    ];

    foreach ($users as $user) {
        $this->actingAs($user)->get('/cabinet/orders')->assertForbidden();
    }
});

it('показывает отправителю только его заказы', function (): void {
    $sender = User::factory()->sender()->create();
    $other = User::factory()->sender()->create();

    $own = DeliveryOrder::factory()->create(['sender_id' => $sender->id]);
    $foreign = DeliveryOrder::factory()->create(['sender_id' => $other->id]);

    $this->actingAs($sender)->get('/cabinet/orders')
        ->assertOk()
        ->assertSee(__('ui.nav.orders'))
        ->assertSee($own->number)
        ->assertDontSee($foreign->number);
});

it('отправляет неподтверждённого отправителя на подтверждение почты', function (): void {
    $sender = User::factory()->unverified()->sender()->create();

    $this->actingAs($sender)->get('/cabinet/orders')
        ->assertRedirect(route('verification.notice'));
});

it('укладывает рендер списка в три запроса', function (): void {
    $sender = User::factory()->sender()->create();
    DeliveryOrder::factory()->count(3)->create(['sender_id' => $sender->id]);

    $selects = 0;

    DB::listen(static function (QueryExecuted $query) use (&$selects): void {
        if (str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            $selects++;
        }
    });

    $this->actingAs($sender)->get('/cabinet/orders')->assertOk();

    expect($selects)->toBeLessThanOrEqual(3);
});
