<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Демонстрационные данные
    |--------------------------------------------------------------------------
    |
    | Набор для показа и ручной проверки: учётные записи всех ролей, зоны
    | вне демонстрационной и заказы во всех статусах. Значения читаются
    | сидером Database\Seeders\DemoSeeder и командой demo:accounts.
    |
    */

    // Пароль всех демонстрационных учётных записей. Не секрет: набор живёт
    // только в local и testing.
    'password' => env('DEMO_PASSWORD', 'password'),

    // Окружения, где допустимо наполнять демонстрационные данные.
    'environments' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('DEMO_SEED_ENVIRONMENTS', 'local,testing')),
    ))),

    // Код зоны из GeographyDemoSeeder: она уже содержит справочник адресов.
    'home_zone_code' => 'msk-demo',

    /*
    | Учётные записи демонстрации.
    |
    | Адреса sender@, courier@, dispatcher@ и admin@ фиксированы: на них
    | опираются README и тест IdentitySchemaTest.
    */
    'accounts' => [
        ['email' => 'sender@courier.test', 'name' => 'Отправитель Демо', 'role' => 'sender'],
        ['email' => 'sender2@courier.test', 'name' => 'Отправитель Демо 2', 'role' => 'sender'],
        ['email' => 'sender3@courier.test', 'name' => 'Отправитель Демо 3', 'role' => 'sender'],
        ['email' => 'dispatcher@courier.test', 'name' => 'Диспетчер Демо', 'role' => 'dispatcher'],
        ['email' => 'dispatcher2@courier.test', 'name' => 'Диспетчер Демо 2', 'role' => 'dispatcher'],
        ['email' => 'admin@courier.test', 'name' => 'Администратор Демо', 'role' => 'admin'],
        ['email' => 'courier@courier.test', 'name' => 'Курьер Демо', 'role' => 'courier', 'transport' => 'light', 'kind' => 'bicycle'],
        ['email' => 'courier2@courier.test', 'name' => 'Курьер Демо 2', 'role' => 'courier', 'transport' => 'on_foot'],
        ['email' => 'courier3@courier.test', 'name' => 'Курьер Демо 3', 'role' => 'courier', 'transport' => 'light', 'kind' => 'e_bike'],
        ['email' => 'courier4@courier.test', 'name' => 'Курьер Демо 4', 'role' => 'courier', 'transport' => 'light', 'kind' => 'scooter'],
        ['email' => 'courier5@courier.test', 'name' => 'Курьер Демо 5', 'role' => 'courier', 'transport' => 'car'],
        ['email' => 'courier6@courier.test', 'name' => 'Курьер Демо 6', 'role' => 'courier', 'transport' => 'on_foot'],
    ],

    /*
    | Зоны вне прямоугольника демо-зоны.
    |
    | Нужны, чтобы справочник выглядел реалистично и чтобы проверка зоны
    | оставалась воспроизводимой: msk-demo остаётся единственной зоной,
    | покрывающей демонстрационные адреса.
    */
    'zones' => [
        ['code' => 'msk-north', 'name' => 'Москва, север', 'base_fee_amount' => 21900],
        ['code' => 'msk-south', 'name' => 'Москва, юг', 'base_fee_amount' => 20900],
        ['code' => 'msk-east', 'name' => 'Москва, восток', 'base_fee_amount' => 20900],
        ['code' => 'msk-west', 'name' => 'Москва, запад', 'base_fee_amount' => 20900],
        ['code' => 'msk-center', 'name' => 'Москва, центр', 'base_fee_amount' => 24900],
        ['code' => 'msk-outside-a', 'name' => 'Область, север', 'base_fee_amount' => 29900],
        ['code' => 'msk-outside-b', 'name' => 'Область, юг', 'base_fee_amount' => 29900],
    ],

    // Сколько заказов создаётся и сколько их приходится на каждый статус.
    'orders_total' => 20,
    'orders_per_status' => [
        'created' => 3,
        'assigned' => 2,
        'accepted' => 2,
        'picked_up' => 2,
        'delivered' => 5,
        'closed' => 2,
        'cancelled' => 3,
        'failed' => 1,
    ],
];
