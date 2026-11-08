<?php

declare(strict_types=1);

use App\Domain\Order\OrderStatus;

/*
|--------------------------------------------------------------------------
| Blade-компоненты карточек, списков и состояний (ЧТЗ 9.3, пункты 4, 5, 7, 8)
|--------------------------------------------------------------------------
|
| Проверяется только представление: статус читается текстом, пустое
| состояние даёт действие, прокрутка доступна с клавиатуры и подписана,
| а список на узкой ширине превращается в карточки без горизонтальной
| прокрутки. База, Actions и сеть не нужны.
|
*/

it('выводит текстовую подпись статуса заказа', function (): void {
    $html = $this->blade('<x-app.status-badge :status="$status" />', [
        'status' => OrderStatus::PickedUp,
    ])->__toString();

    expect($html)
        ->toContain('Забран')
        ->toContain('badge')
        ->toContain('badge-warning');
});

it('подписывает строковый статус через перечисление', function (): void {
    $html = $this->blade('<x-app.status-badge status="created" />')->__toString();

    expect($html)->toContain('Создан');
});

it('выводит действие пустого состояния из слота', function (): void {
    $html = $this->blade(
        '<x-app.empty-state title="Заказов нет" description="Здесь появятся ваши заказы.">'
        .'<x-slot:action><a class="btn btn-primary" href="/cabinet/orders/create">Создать заказ</a></x-slot:action>'
        .'</x-app.empty-state>',
    )->__toString();

    expect($html)
        ->toContain('Заказов нет')
        ->toContain('Здесь появятся ваши заказы.')
        ->toContain('empty-state-action')
        ->toContain('Создать заказ');
});

it('делает область прокрутки доступной с клавиатуры и подписанной', function (): void {
    $html = $this->blade(
        '<x-app.scroll-area label="Список заказов" max-height="18rem"><p>Содержимое</p></x-app.scroll-area>',
    )->__toString();

    expect($html)
        ->toContain('tabindex="0"')
        ->toContain('role="region"')
        ->toContain('aria-label="Список заказов"')
        ->toContain('overflow-y-auto')
        ->toContain('max-height: 18rem');
});

it('выводит строки таблицы и карточки с полным значением в title', function (): void {
    $long = 'улица Очень-Длинное-Название-Улицы, дом 123, корпус 4, квартира 56';

    $html = $this->blade(
        '<x-app.data-list :columns="$columns" :rows="$rows" />',
        [
            'columns' => ['number' => 'Номер', 'address' => 'Адрес'],
            'rows' => [
                ['number' => 'K-1001', 'address' => $long],
            ],
        ],
    )->__toString();

    expect($html)
        ->toContain('data-list-table')
        ->toContain('data-list-cards')
        ->toContain('<th scope="col">Номер</th>')
        ->toContain('K-1001')
        ->toContain('title="'.$long.'"');
});

it('выводит карточку с заголовком, подзаголовком и действиями', function (): void {
    $html = $this->blade(
        '<x-app.card title="Кандидаты" subtitle="Рядом с заказом">'
        .'<x-slot:actions><button type="button" class="btn btn-sm">Обновить</button></x-slot:actions>'
        .'<p>Содержимое</p>'
        .'</x-app.card>',
    )->__toString();

    expect($html)
        ->toContain('card-header')
        ->toContain('Кандидаты')
        ->toContain('Рядом с заказом')
        ->toContain('card-actions')
        ->toContain('Содержимое');
});

it('отмечает активную вкладку и связывает её с панелью', function (): void {
    $html = $this->blade(
        '<x-app.tabs name="orders" :active="$active" :tabs="$tabs">'
        .'<div role="tabpanel" id="orders-panel-history">История</div>'
        .'</x-app.tabs>',
        [
            'active' => 'history',
            'tabs' => ['list' => 'Список', 'history' => 'История'],
        ],
    )->__toString();

    expect($html)
        ->toContain('role="tablist"')
        ->toContain('aria-selected="true"')
        ->toContain('aria-controls="orders-panel-history"')
        ->toContain('id="orders-tab-history"')
        ->toContain('tabindex="-1"');
});

it('показывает «Показать ещё» и возврат к началу выдачи', function (): void {
    $html = $this->blade(
        '<x-app.pagination :has-more="true" next-url="/cabinet/orders?cursor=abc" prev-url="/cabinet/orders" />',
    )->__toString();

    expect($html)
        ->toContain('rel="next"')
        ->toContain('Показать ещё')
        ->toContain('rel="prev"')
        ->toContain('В начало списка');
});
