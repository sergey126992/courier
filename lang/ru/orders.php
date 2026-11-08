<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Тексты ограничений заказов
|--------------------------------------------------------------------------
|
| Ключи совпадают со значениями перечисления App\Http\Problem\ErrorCode.
| Тексты не раскрывают чужих данных и внутренних подробностей.
|
*/

return [
    'errors' => [
        'REQUESTED_AT_IN_PAST' => 'Момент заявки не может быть в прошлом: допускается расхождение часов до :minutes минут.',
        'IDEMPOTENCY_KEY_INVALID' => 'Заголовок Idempotency-Key обязателен и должен содержать от :min до :max символов.',
        'ORDER_STATUS_TRANSITION_NOT_ALLOWED' => 'Статус заказа «:current» не позволяет перейти в «:target».',
    ],
    // Сообщения веб-сценариев кабинета.
    'messages' => [
        'created' => 'Заказ создан.',
        'cancelled' => 'Заказ отменён.',
        'cancel_conflict' => 'Заказ нельзя отменить в текущем статусе.',
        'tracking_link_created' => 'Ссылка на отслеживание создана.',
    ],

    // Подписи строк списка и карточки заказа.
    'labels' => [
        'number' => 'Номер',
        'status' => 'Статус',
        'transport' => 'Транспорт',
        'weight' => 'Вес',
        'eta' => 'Время в пути',
        'price' => 'Стоимость',
        'created_at' => 'Создан',
        'zone' => 'Зона',
        'pickup' => 'Откуда',
        'dropoff' => 'Куда',
        'tracking_code' => 'Код отслеживания',
        'tracking' => 'Отслеживание',
        'history' => 'История статусов',
        'cancel_reason' => 'Причина отмены',
        'no_history' => 'История статусов пуста.',
        'parcel' => 'Посылка',
        'declared_value' => 'Заявленная стоимость',
        'requested_at' => 'Желаемое время доставки',
        'cancelled_at' => 'Отменён',
        'changed_by' => 'Автор',
        'reason' => 'Причина',
        'system' => 'Система',
        'notifications' => 'Уведомления',
        'notifications_empty' => 'Данных о доставке уведомлений пока нет.',
        'notification_channel_email' => 'Email',
        'notification_channel_push' => 'Push',
        'notification_status_sent' => 'доставлено',
        'notification_status_failed' => 'не доставлено',
    ],
];
