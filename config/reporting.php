<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Отчёты: валюта, период и бенчмарк
|--------------------------------------------------------------------------
|
| Значения по умолчанию соответствуют ЧТЗ «Отчёты и производительность»
| (FR-RPT-144, FR-RPT-146, FR-RPT-151, FR-RPT-181…186). Валюта отчёта и
| предельная длительность периода не вычисляются из данных и не берутся из
| литералов в коде: они читаются здесь. Правило периода по умолчанию —
| начало текущего месяца по текущий момент. Список типов транспорта для
| разреза живёт в домене (`TransportType`), а не в конфигурации.
|
*/

return [
    // Код валюты отчёта (FR-RPT-151): все денежные значения — минорные единицы.
    'currency' => (string) env('REPORTING_CURRENCY', 'RUB'),

    // Наибольшая длительность периода в днях (FR-RPT-146): измеряется в секундах.
    'max_period_days' => (int) env('REPORTING_MAX_PERIOD_DAYS', 366),

    // Правило периода по умолчанию (FR-RPT-144): начало текущего месяца — текущий момент.
    'default_period' => [
        'from' => (string) env('REPORTING_DEFAULT_PERIOD_FROM', 'start_of_month'),
        'to' => (string) env('REPORTING_DEFAULT_PERIOD_TO', 'now'),
    ],

    // Бенчмарк отчётного запроса (FR-RPT-161…168): размер набора, число замеров
    // и окружения, где допустимо наполнение данными.
    'benchmark' => [
        'orders' => (int) env('REPORTING_BENCHMARK_ORDERS', 100000),
        'runs' => (int) env('REPORTING_BENCHMARK_RUNS', 20),
        'environments' => array_map(
            static fn (string $value): string => trim($value),
            explode(',', (string) env('REPORTING_BENCHMARK_ENVIRONMENTS', 'local,testing')),
        ),
    ],
];
