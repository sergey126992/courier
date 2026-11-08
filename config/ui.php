<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Интерфейс: бренд, тема, домашние страницы ролей и общие параметры
|--------------------------------------------------------------------------
|
| FR-UI-196: значения интерфейса задаются здесь и только здесь. Шаблоны и
| классы читают их через config('ui.*'), а env() вызывается только в конфиге.
| Подписи экранов остаются в lang/ru: здесь только значения конфигурации.
|
*/

return [
    // Название сервиса для заголовков страниц и писем.
    'name' => env('UI_NAME', 'Курьерская служба'),

    // Короткое имя бренда в шапке.
    'brand' => env('UI_BRAND', 'Курьер'),

    // Локаль интерфейса: проект одноезычный, но значение не литерал в коде.
    'locale' => env('UI_LOCALE', 'ru'),

    // Тема оформления: светлая, тёмная или как в системе (FR-UI-152…155).
    'theme' => [
        'default' => env('UI_THEME_DEFAULT', 'system'),
        'cookie' => env('UI_THEME_COOKIE', 'ui_theme'),
    ],

    // Домашняя страница роли: сюда ведёт вход и редирект с гостевых страниц.
    'roles' => [
        'sender' => [
            'home' => env('UI_HOME_SENDER', 'cabinet.orders'),
        ],
        'courier' => [
            'home' => env('UI_HOME_COURIER', 'account'),
        ],
        'dispatcher' => [
            'home' => env('UI_HOME_DISPATCHER', 'dispatcher.queue'),
        ],
        'admin' => [
            'home' => env('UI_HOME_ADMIN', 'account'),
        ],
    ],

    // Курсорная выдача списков (раздел 7 ЧТЗ).
    'pagination' => [
        'per_page' => (int) env('UI_PAGINATION_PER_PAGE', 20),
        'max' => (int) env('UI_PAGINATION_MAX', 100),
    ],

    // Интервал автообновления очереди диспетчера в секундах (FR-UI-133).
    'dispatcher' => [
        'refresh_seconds' => (int) env('UI_DISPATCHER_REFRESH_SECONDS', 20),
    ],

    // Параметры блока трекинга: имя web-маршрута публичной страницы (FR-UI-129)
    // и интервал автообновления в секундах (FR-UI-128, 177).
    'tracking' => [
        'public_route' => env('UI_TRACKING_PUBLIC_ROUTE', 'tracking.public.web'),
        'refresh_seconds' => (int) env('UI_TRACKING_REFRESH_SECONDS', 30),
    ],

    // Имена web-маршрутов из писем подтверждения и сброса пароля (FR-UI-106, 107).
    'links' => [
        'email_verification_route' => env('UI_EMAIL_VERIFICATION_ROUTE', 'verification.verify.web'),
        'password_reset_route' => env('UI_PASSWORD_RESET_ROUTE', 'password.reset'),
    ],
];
