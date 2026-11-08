<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Уведомления: админ-страницы и служебный API сервиса
|--------------------------------------------------------------------------
|
| Матрица «событие → получатель → каналы», пределы попыток, backoff и сроки
| хранения существуют только в конфигурации сервиса оповещений: второго
| отправителя в монолите нет (FR-NTF-403, FR-NTF-406). Здесь остаются только
| параметры прокси-страниц администратора и клиента служебного API (ЧТЗ 8.2).
|
*/

return [
    // Админ-страницы уведомлений: размер порции журнала (FR-NTF-405).
    'admin' => [
        'page_size' => (int) env('NOTIFICATIONS_ADMIN_PAGE_SIZE', 20),
    ],

    // Служебный API сервиса оповещений для проксирующих маршрутов (ЧТЗ 8.2).
    // Токен передаётся заголовком Authorization и в лог не попадает (FR-OPS-206).
    // Таймаут, число повторов и пауза — из конфигурации, литералов в коде нет.
    'service' => [
        'url' => (string) env('NOTIFICATIONS_SERVICE_URL', 'http://notifications-app:8000'),
        'token' => (string) env('NOTIFICATIONS_SERVICE_TOKEN', ''),
        'timeout_seconds' => max(1, (int) env('NOTIFICATIONS_SERVICE_TIMEOUT_SECONDS', 5)),
        'retry_times' => max(0, (int) env('NOTIFICATIONS_SERVICE_RETRY_TIMES', 1)),
        'retry_sleep_milliseconds' => max(0, (int) env('NOTIFICATIONS_SERVICE_RETRY_SLEEP_MS', 100)),
    ],
];
