<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Блок статуса доставки уведомлений (FR-NTF-284, FR-UI-158, 176)
|--------------------------------------------------------------------------
|
| Компактный элемент карточки заказа: канал и подпись состояния читаются
| текстом, состояние дополняется признаком `data-state` для тона. Пустой
| список — нейтральное состояние, а не ошибка страницы.
|
*/

it('выводит статус доставки по каналам', function (): void {
    $html = $this->blade('<x-app.notification-status :items="$items" />', [
        'items' => [
            ['channel' => 'Email', 'status' => 'доставлено', 'state' => 'sent'],
            ['channel' => 'Push', 'status' => 'не доставлено', 'state' => 'failed'],
        ],
    ])->__toString();

    expect($html)
        ->toContain('Email')
        ->toContain('доставлено')
        ->toContain('data-state="sent"')
        ->toContain('Push')
        ->toContain('не доставлено')
        ->toContain('data-state="failed"');
});

it('показывает нейтральное состояние без данных проекции', function (): void {
    $html = $this->blade('<x-app.notification-status :items="[]" />')->__toString();

    expect($html)
        ->toContain((string) __('orders.labels.notifications_empty'))
        ->toContain('empty-state');
});
