<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Параметры расчёта стоимости и прогноза доставки
|--------------------------------------------------------------------------
|
| Значения по умолчанию соответствуют ЧТЗ «Расчёт стоимости и ETA» (раздел 6).
| Суммы заданы в минорных единицах (копейках), скорости — в км/ч,
| проценты надбавок — в процентах, время на операции — в минутах.
|
| Любой параметр переопределяется переменной окружения, поэтому тариф
| меняется настройкой, а не правкой кода стратегии.
|
*/

return [
    // Валюта расчёта: поддержана типом Money, но проект работает на рубле.
    'currency' => env('PRICING_CURRENCY', 'RUB'),

    // Часы пик: границы интервалов включаются, время трактуется в часовом поясе приложения.
    'peak_hours' => [
        'intervals' => [
            [
                'from' => env('PRICING_PEAK_MORNING_FROM', '07:00'),
                'to' => env('PRICING_PEAK_MORNING_TO', '10:00'),
            ],
            [
                'from' => env('PRICING_PEAK_EVENING_FROM', '17:00'),
                'to' => env('PRICING_PEAK_EVENING_TO', '20:00'),
            ],
        ],
    ],

    'transport' => [
        'on_foot' => [
            'max_weight_grams' => (int) env('PRICING_ON_FOOT_MAX_WEIGHT_GRAMS', 3000),
            'max_radius_km' => (float) env('PRICING_ON_FOOT_MAX_RADIUS_KM', 2),
            'base_rate_minor' => (int) env('PRICING_ON_FOOT_BASE_RATE_MINOR', 9900),
            'rate_per_km_minor' => (int) env('PRICING_ON_FOOT_RATE_PER_KM_MINOR', 2000),
            'speed_kmh' => (float) env('PRICING_ON_FOOT_SPEED_KMH', 4.5),
            'peak_speed_kmh' => null,
            'handling_minutes' => (int) env('PRICING_ON_FOOT_HANDLING_MINUTES', 10),
            'peak_surcharge_percent' => 0,
            'parking_fee_minor' => null,
            'speeds_kmh' => [],
        ],

        'light' => [
            'max_weight_grams' => (int) env('PRICING_LIGHT_MAX_WEIGHT_GRAMS', 15000),
            'max_radius_km' => (float) env('PRICING_LIGHT_MAX_RADIUS_KM', 7),
            'base_rate_minor' => (int) env('PRICING_LIGHT_BASE_RATE_MINOR', 14900),
            'rate_per_km_minor' => (int) env('PRICING_LIGHT_RATE_PER_KM_MINOR', 2500),
            // Обычная скорость лёгкого транспорта не используется: скорость задаётся видом.
            'speed_kmh' => (float) env('PRICING_LIGHT_SPEED_KMH', 12),
            'peak_speed_kmh' => null,
            'handling_minutes' => (int) env('PRICING_LIGHT_HANDLING_MINUTES', 7),
            'peak_surcharge_percent' => (float) env('PRICING_LIGHT_PEAK_SURCHARGE_PERCENT', 10),
            'parking_fee_minor' => null,
            'speeds_kmh' => [
                'bicycle' => (float) env('PRICING_LIGHT_BICYCLE_SPEED_KMH', 15),
                'scooter' => (float) env('PRICING_LIGHT_SCOOTER_SPEED_KMH', 12),
                'e_scooter' => (float) env('PRICING_LIGHT_E_SCOOTER_SPEED_KMH', 18),
                'e_bike' => (float) env('PRICING_LIGHT_E_BIKE_SPEED_KMH', 20),
            ],
        ],

        'car' => [
            'max_weight_grams' => (int) env('PRICING_CAR_MAX_WEIGHT_GRAMS', 100000),
            'max_radius_km' => (float) env('PRICING_CAR_MAX_RADIUS_KM', 20),
            'base_rate_minor' => (int) env('PRICING_CAR_BASE_RATE_MINOR', 24900),
            'rate_per_km_minor' => (int) env('PRICING_CAR_RATE_PER_KM_MINOR', 3500),
            'speed_kmh' => (float) env('PRICING_CAR_SPEED_KMH', 25),
            'peak_speed_kmh' => (float) env('PRICING_CAR_PEAK_SPEED_KMH', 18),
            'handling_minutes' => (int) env('PRICING_CAR_HANDLING_MINUTES', 5),
            'peak_surcharge_percent' => (float) env('PRICING_CAR_PEAK_SURCHARGE_PERCENT', 15),
            'parking_fee_minor' => (int) env('PRICING_CAR_PARKING_FEE_MINOR', 5000),
            'speeds_kmh' => [],
        ],
    ],
];
