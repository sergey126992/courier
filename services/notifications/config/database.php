<?php

declare(strict_types=1);

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Подключения сервиса оповещений
|--------------------------------------------------------------------------
|
| Сервис работает с одной СУБД — PostgreSQL 18 — и собственной базой
| notifications (FR-NTF-212). Строка подключения монолита сервису
| недоступна: роль notifications не имеет CONNECT к базе courier.
|
*/
return [
    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [
        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'pgsql'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'notifications'),
            'username' => env('DB_USERNAME', 'notifications'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
            // Таймаут подключения (FR-OPS-205): libpq читает его из окружения
            // процесса, значение выставляет поставщик услуг из этого ключа.
            'connect_timeout' => (int) env('DB_CONNECT_TIMEOUT', 5),
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    // Redis используется как транспорт событий (Redis Streams) и как хранилище
    // снимка метрик M13: значения общие для процессов отправки, расписания
    // и HTTP, поэтому память процесса для них не подходит.
    'redis' => [
        'client' => env('REDIS_CLIENT', 'predis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'notifications-service')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', 'redis'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            // Таймауты подключения и чтения (FR-OPS-205): predis берёт их
            // из параметров соединения, литералов в коде нет.
            'timeout' => (float) env('REDIS_CONNECT_TIMEOUT', 5),
            'read_write_timeout' => (float) env('REDIS_READ_TIMEOUT', 60),
        ],

        // Соединение кэша снимка метрик (FR-OPS-508): тот же Redis, но
        // отдельная база, поэтому ключи кэша не пересекаются с данными
        // транспорта событий. Номер базы задаётся окружением.
        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', 'redis'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'timeout' => (float) env('REDIS_CONNECT_TIMEOUT', 5),
            'read_write_timeout' => (float) env('REDIS_READ_TIMEOUT', 60),
        ],
    ],
];
