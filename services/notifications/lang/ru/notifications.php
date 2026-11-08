<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Тексты уведомлений
|--------------------------------------------------------------------------
|
| Ключ — стабильное имя шаблона домена «тип события — канал» (FR-NTF-247).
| В теме и теле только номер заказа, статус и ссылка трекинга: имя, адрес,
| телефон и email получателя в тексты не попадают (FR-NTF-251, FR-NTF-258).
| Тексты совпадают с правилами M7 монолита; ссылка трекинга добавлена
| шаблонами сервиса.
|
*/

return [
    // Заголовки ошибок API сервиса (ЧТЗ 8.5, FR-OPS-207): без внутренних
    // деталей, сообщений провайдеров и персональных данных.
    'problem' => [
        'INVALID_SERVICE_TOKEN' => 'Требуется сервисный токен',
        'NOT_FOUND' => 'Ресурс не найден',
        'NOTIFICATION_ALREADY_SENT' => 'Уведомление уже отправлено',
        'VALIDATION_FAILED' => 'Некорректный запрос',
        'INTERNAL_ERROR' => 'Внутренняя ошибка сервиса',
        'SERVICE_UNAVAILABLE' => 'Сервис недоступен',
        'TELEGRAM_LINK_TOKEN_INVALID' => 'Токен привязки недействителен',
        'TELEGRAM_LINK_TOKEN_EXPIRED' => 'Срок действия токена привязки истёк',
        'TELEGRAM_BINDING_ABSENT' => 'Привязка Telegram не найдена',
        'TELEGRAM_CHANNEL_DISABLED' => 'Канал Telegram выключен',
        'TELEGRAM_WEBHOOK_SECRET_INVALID' => 'Неверный секрет вебхука Telegram',
        'PREFERENCE_INVALID_EVENT_TYPE' => 'Неизвестный тип события',
        'PREFERENCE_INVALID_CHANNEL' => 'Некорректный канал',
        'PREFERENCE_INVALID_LOCALE' => 'Некорректная локаль',
        'PREFERENCE_INVALID_QUIET_HOURS' => 'Некорректное окно тихих часов',
        'PREFERENCE_CRITICAL_CHANNEL_REQUIRED' => 'Email критичного события обязателен',
        'PREFERENCE_EVENT_TYPE_NOT_ALLOWED' => 'Тип события недоступен роли получателя',
        'DLQ_ENTRY_NOT_FOUND' => 'Запись очереди не найдена',
        'DLQ_ENTRY_ALREADY_RESOLVED' => 'Запись уже разобрана или уже в повторе',
        'DEVICE_TOKEN_INVALID' => 'Некорректные поля токена устройства',
        'DEVICE_TOKEN_UNKNOWN' => 'Токен устройства не найден',
        'METRICS_UNAVAILABLE' => 'Источник метрик недоступен',
    ],

    // Ответы бота Telegram (FR-NTF-415, ЧТЗ 5.4 п. 3): токена нет либо он
    // недействителен — общая подсказка; истёкший токен — отдельный текст,
    // чтобы пользователь выпустил новую ссылку. Персональных данных,
    // токенов и deep-link в текстах нет.
    'telegram' => [
        'link_hint' => 'Чтобы привязать чат, откройте настройки уведомлений '
            .'в личном кабинете и нажмите «Привязать Telegram».',
        'link_expired' => 'Ссылка привязки истекла. Выпустите новую '
            .'в настройках уведомлений.',
    ],

    'templates' => [
        'order_created_email' => [
            'subject' => 'Заказ №:number принят',
            'greeting' => 'Здравствуйте!',
            'body' => 'Заказ №:number принят в работу. Текущий статус: :status.',
            'tracking' => 'Отследить заказ: :tracking_url',
            'signature' => 'С уважением, курьерская служба',
        ],
        'order_assigned_push' => [
            'subject' => 'Заказ №:number назначен курьеру',
            'greeting' => 'Здравствуйте!',
            'body' => 'Заказ №:number: курьер назначен. Статус: :status.',
            'tracking' => 'Отследить заказ: :tracking_url',
            'signature' => 'Курьерская служба',
        ],
        'order_picked_up_email' => [
            'subject' => 'Заказ №:number забран курьером',
            'greeting' => 'Здравствуйте!',
            'body' => 'Курьер забрал заказ №:number. Текущий статус: :status.',
            'tracking' => 'Отследить заказ: :tracking_url',
            'signature' => 'С уважением, курьерская служба',
        ],
        'order_delivered_email' => [
            'subject' => 'Заказ №:number доставлен',
            'greeting' => 'Здравствуйте!',
            'body' => 'Заказ №:number доставлен получателю. Текущий статус: :status.',
            'tracking' => 'Отследить заказ: :tracking_url',
            'signature' => 'С уважением, курьерская служба',
        ],
        'order_delivered_push' => [
            'subject' => 'Заказ №:number доставлен',
            'greeting' => 'Здравствуйте!',
            'body' => 'Заказ №:number доставлен. Статус: :status.',
            'tracking' => 'Отследить заказ: :tracking_url',
            'signature' => 'Курьерская служба',
        ],
        'order_failed_email' => [
            'subject' => 'Заказ №:number не выполнен',
            'greeting' => 'Здравствуйте!',
            'body' => 'Заказ №:number не выполнен. Текущий статус: :status.',
            'tracking' => 'Отследить заказ: :tracking_url',
            'signature' => 'С уважением, курьерская служба',
        ],
        'order_cancelled_email' => [
            'subject' => 'Заказ №:number отменён',
            'greeting' => 'Здравствуйте!',
            'body' => 'Заказ №:number отменён. Текущий статус: :status.',
            'tracking' => 'Отследить заказ: :tracking_url',
            'signature' => 'С уважением, курьерская служба',
        ],
        'order_cancelled_push' => [
            'subject' => 'Заказ №:number отменён',
            'greeting' => 'Здравствуйте!',
            'body' => 'Заказ №:number отменён. Статус: :status.',
            'tracking' => 'Отследить заказ: :tracking_url',
            'signature' => 'Курьерская служба',
        ],

        // Тексты канала Telegram (FR-NTF-434): только номер заказа, статус
        // и ссылка трекинга. Персональных данных и `chat_id` в тексте нет.
        'order_created_telegram' => [
            'body' => 'Заказ №:number принят в работу. Текущий статус: :status.',
            'tracking' => 'Отследить заказ: :tracking_url',
        ],
        'order_assigned_telegram' => [
            'body' => 'Заказ №:number: курьер назначен. Текущий статус: :status.',
            'tracking' => 'Отследить заказ: :tracking_url',
        ],
        'order_picked_up_telegram' => [
            'body' => 'Курьер забрал заказ №:number. Текущий статус: :status.',
            'tracking' => 'Отследить заказ: :tracking_url',
        ],
        'order_delivered_telegram' => [
            'body' => 'Заказ №:number доставлен получателю. Текущий статус: :status.',
            'tracking' => 'Отследить заказ: :tracking_url',
        ],
        'order_failed_telegram' => [
            'body' => 'Заказ №:number не выполнен. Текущий статус: :status.',
            'tracking' => 'Отследить заказ: :tracking_url',
        ],
        'order_cancelled_telegram' => [
            'body' => 'Заказ №:number отменён. Текущий статус: :status.',
            'tracking' => 'Отследить заказ: :tracking_url',
        ],
    ],
];
