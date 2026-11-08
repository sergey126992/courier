<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Заказы на доставку
|--------------------------------------------------------------------------
|
| Значения по умолчанию соответствуют ЧТЗ «География и заказы» (FR-ORD-112,
| FR-ORD-113): допуск расхождения часов при проверке момента заявки и срок
| хранения ключей идемпотентности.
|
*/

return [
    // Момент заявки не может быть в прошлом; небольшое расхождение часов допускается.
    'requested_at_tolerance_minutes' => (int) env('ORDERS_REQUESTED_AT_TOLERANCE_MINUTES', 5),

    'idempotency' => [
        // Срок хранения ключей повтора в часах (FR-ORD-112).
        'ttl_hours' => (int) env('ORDERS_IDEMPOTENCY_TTL_HOURS', 24),

        // Длина ключа из заголовка Idempotency-Key (FR-ORD-111).
        'key_min_length' => (int) env('ORDERS_IDEMPOTENCY_KEY_MIN_LENGTH', 16),
        'key_max_length' => (int) env('ORDERS_IDEMPOTENCY_KEY_MAX_LENGTH', 64),
    ],
];
