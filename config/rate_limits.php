<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Ограничения частоты запросов доступа
|--------------------------------------------------------------------------
|
| Значения задаются конфигурацией, а не литералами в маршрутах.
| attempts — сколько запросов разрешено в окне, decay_seconds — размер окна.
| Ключи лимитов настраиваются в App\Providers\AppServiceProvider.
|
*/

return [
    // Вход: ключ — email и IP, чтобы перебор одного аккаунта не обходился сменой адреса.
    'login' => [
        'attempts' => (int) env('RATE_LIMIT_LOGIN_ATTEMPTS', 5),
        'decay_seconds' => (int) env('RATE_LIMIT_LOGIN_DECAY', 60),
    ],

    // Регистрация: ключ — IP, массовое создание аккаунтов с одного адреса закрывается.
    'register' => [
        'attempts' => (int) env('RATE_LIMIT_REGISTER_ATTEMPTS', 10),
        'decay_seconds' => (int) env('RATE_LIMIT_REGISTER_DECAY', 3600),
    ],

    // Повторная отправка письма подтверждения: ключ — пользователь.
    'email_resend' => [
        'attempts' => (int) env('RATE_LIMIT_EMAIL_RESEND_ATTEMPTS', 3),
        'decay_seconds' => (int) env('RATE_LIMIT_EMAIL_RESEND_DECAY', 600),
    ],

    // Предварительный расчёт: ключ — пользователь, лимит по ЧТЗ 7 — 60 запросов в минуту.
    'estimate' => [
        'attempts' => (int) env('RATE_LIMIT_ESTIMATE_ATTEMPTS', 60),
        'decay_seconds' => (int) env('RATE_LIMIT_ESTIMATE_DECAY', 60),
    ],

    // Создание заказа: ключ — пользователь, значение из ЧТЗ — 30 запросов в минуту.
    'orders_create' => [
        'attempts' => (int) env('RATE_LIMIT_ORDERS_CREATE_ATTEMPTS', 30),
        'decay_seconds' => (int) env('RATE_LIMIT_ORDERS_CREATE_DECAY', 60),
    ],

    // Запрос сброса пароля: ключ — IP, письма не рассылаются пачками.
    'password_forgot' => [
        'attempts' => (int) env('RATE_LIMIT_PASSWORD_FORGOT_ATTEMPTS', 3),
        'decay_seconds' => (int) env('RATE_LIMIT_PASSWORD_FORGOT_DECAY', 600),
    ],

    // Публичный просмотр трекинга: ключ — IP, поток открытий ссылки не бьёт в базу (FR-TRK-136).
    'tracking_public' => [
        'attempts' => (int) env('RATE_LIMIT_TRACKING_PUBLIC_ATTEMPTS', 60),
        'decay_seconds' => (int) env('RATE_LIMIT_TRACKING_PUBLIC_DECAY', 60),
    ],

    // Создание ссылки трекинга: ключ — пользователь, ссылки не штампуются пачками (FR-TRK-131).
    'tracking_link' => [
        'attempts' => (int) env('RATE_LIMIT_TRACKING_LINK_ATTEMPTS', 10),
        'decay_seconds' => (int) env('RATE_LIMIT_TRACKING_LINK_DECAY', 60),
    ],

    // Приём геоточек назначения: ключ — курьер, точка отправляется раз в несколько секунд (FR-TRK-108).
    'courier_points' => [
        'attempts' => (int) env('RATE_LIMIT_COURIER_POINTS_ATTEMPTS', 120),
        'decay_seconds' => (int) env('RATE_LIMIT_COURIER_POINTS_DECAY', 60),
    ],

    // Heartbeat курьера: ключ — курьер, отдельный лимит (FR-TRK-113).
    'courier_heartbeat' => [
        'attempts' => (int) env('RATE_LIMIT_COURIER_HEARTBEAT_ATTEMPTS', 120),
        'decay_seconds' => (int) env('RATE_LIMIT_COURIER_HEARTBEAT_DECAY', 60),
    ],

    // Назначение и переназначение: ключ — диспетчер, ручные действия сдерживаются.
    'dispatch_assign' => [
        'attempts' => (int) env('RATE_LIMIT_DISPATCH_ASSIGN_ATTEMPTS', 30),
        'decay_seconds' => (int) env('RATE_LIMIT_DISPATCH_ASSIGN_DECAY', 60),
    ],

    // Действия курьера по назначению: ключ — курьер (FR-DSP-149).
    'courier_actions' => [
        'attempts' => (int) env('RATE_LIMIT_COURIER_ACTIONS_ATTEMPTS', 60),
        'decay_seconds' => (int) env('RATE_LIMIT_COURIER_ACTIONS_DECAY', 60),
    ],

    // Отчёт по заказам: ключ — пользователь, тяжёлый агрегат не запускается пачками (FR-RPT-182).
    'reports_read' => [
        'attempts' => (int) env('RATE_LIMIT_REPORTS_READ_ATTEMPTS', 30),
        'decay_seconds' => (int) env('RATE_LIMIT_REPORTS_READ_DECAY', 60),
    ],
];
