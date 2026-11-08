<?php

declare(strict_types=1);

namespace App\Services\Reporting\Benchmark;

use Illuminate\Support\Facades\DB;

/**
 * Детерминированный набор данных бенчмарка отчёта (FR-RPT-163…168).
 *
 * Набор строится быстрым SQL с `generate_series`, без фабрик и без сети:
 * зона, отправители, курьеры, заказы, вручения, неудачные попытки и смены.
 * Все строки помечены узнаваемым признаком (`BENCH-` в номере заказа и коде
 * зоны, `@benchmark.local` в почте), поэтому очистка удаляет ровно набор и
 * никогда не трогает рабочие данные. Очистка идёт в порядке внешних ключей,
 * а до удаления владельцев проверяется, что зависимых строк не осталось.
 */
final class BenchmarkDataset
{
    /**
     * Отправитель, по которому снимается план кабинета.
     */
    public const SENDER_EMAIL = 'bench-sender-1@benchmark.local';

    private const ZONE_CODE = 'BENCH-ZONE';

    /**
     * Число отправителей: заказы распределены ровно, иначе отправитель
     * с единственным заказом искажал бы замер кабинета.
     */
    private const SENDERS = 50;

    /**
     * Число курьеров: делители 200 дают ровное распределение вручений.
     */
    private const COURIERS = 200;

    /**
     * Смен на курьера в наборе.
     */
    private const SHIFTS_PER_COURIER = 10;

    /**
     * Доля заказов с вручением: из десяти строк — пять.
     */
    private const DELIVERED_EVERY = 10;

    private const DELIVERED_LIMIT = 5;

    /**
     * Окно генерации заказов: отчёт считается за последние 30 дней из 90.
     */
    private const DATASET_DAYS = 90;

    /**
     * Наполняет набор целиком.
     */
    public function seed(BenchmarkOptions $options): void
    {
        // Генератор детерминирован: seed входит и в setseed, и в хеши строк.
        DB::statement('SELECT setseed(CAST(? AS double precision))', [$this->seedFraction($options->seed)]);

        DB::insert(
            <<<'SQL'
                INSERT INTO service_zones (code, name, boundary, base_fee_amount, currency, is_active, created_at, updated_at)
                SELECT CAST(? AS text), 'BENCH zone', CAST('{}' AS jsonb), 0, 'RUB', true, now(), now()
                WHERE NOT EXISTS (SELECT 1 FROM service_zones WHERE code = CAST(? AS text))
                SQL,
            [self::ZONE_CODE, self::ZONE_CODE],
        );

        DB::insert(
            <<<'SQL'
                INSERT INTO users (name, email, password, role, created_at, updated_at)
                SELECT 'BENCH sender ' || g,
                       'bench-sender-' || g || '@benchmark.local',
                       '', 'sender', now(), now()
                FROM generate_series(1, CAST(? AS integer)) AS g
                WHERE NOT EXISTS (
                    SELECT 1 FROM users WHERE email = 'bench-sender-' || g || '@benchmark.local'
                )
                SQL,
            [self::SENDERS],
        );

        DB::insert(
            <<<'SQL'
                INSERT INTO users (name, email, password, role, created_at, updated_at)
                SELECT 'BENCH courier ' || g, 'bench-courier-' || g || '@benchmark.local', '', 'courier', now(), now()
                FROM generate_series(1, CAST(? AS integer)) AS g
                SQL,
            [self::COURIERS],
        );

        $this->seedOrders($options);
        $this->seedAssignments($options);
        $this->seedShifts($options);

        $this->refreshStatistics();
    }

    /**
     * Статистика после наполнения.
     *
     * VACUUM убирает мёртвые строки прошлых наборов и делает видимой карту
     * видимости, иначе индексные планы читают удалённые строки и замер
     * завышается. В транзакции (тесты RefreshDatabase) VACUUM запрещён —
     * там остаётся ANALYZE.
     */
    public function refreshStatistics(): void
    {
        $tables = ['delivery_orders', 'assignments', 'courier_shifts', 'users'];
        $inTransaction = DB::connection()->transactionLevel() > 0;

        foreach ($tables as $table) {
            DB::statement($inTransaction ? 'ANALYZE '.$table : 'VACUUM (ANALYZE) '.$table);
        }
    }

    /**
     * Размер набора по маркеру.
     *
     * @return array{orders: int, couriers: int, assignments: int, shifts: int}
     */
    public function stats(): array
    {
        $row = DB::selectOne(
            <<<'SQL'
                SELECT
                    (SELECT count(*) FROM delivery_orders WHERE number LIKE 'BENCH-%') AS orders,
                    (SELECT count(*) FROM users WHERE email LIKE '%@benchmark.local' AND role = 'courier') AS couriers,
                    (SELECT count(*) FROM assignments
                     WHERE order_id IN (SELECT id FROM delivery_orders WHERE number LIKE 'BENCH-%')) AS assignments,
                    (SELECT count(*) FROM courier_shifts
                     WHERE courier_id IN (SELECT id FROM users WHERE email LIKE '%@benchmark.local')) AS shifts
                SQL,
        );

        $data = (array) $row;

        return [
            'orders' => (int) $data['orders'],
            'couriers' => (int) $data['couriers'],
            'assignments' => (int) $data['assignments'],
            'shifts' => (int) $data['shifts'],
        ];
    }

    /**
     * Удаляет строки бенчмарка в порядке внешних ключей и возвращает остатки.
     *
     * @return array<string, int> ненулевые остатки по таблицам
     */
    public function cleanup(): array
    {
        DB::delete(
            <<<'SQL'
                DELETE FROM assignments
                WHERE order_id IN (SELECT id FROM delivery_orders WHERE number LIKE 'BENCH-%')
                   OR courier_id IN (SELECT id FROM users WHERE email LIKE '%@benchmark.local')
                SQL,
        );

        DB::delete(
            <<<'SQL'
                DELETE FROM courier_shifts
                WHERE courier_id IN (SELECT id FROM users WHERE email LIKE '%@benchmark.local')
                SQL,
        );

        // Зависимые строки проверяются до удаления владельцев: иначе внешние
        // ключи запретят удаление и остатки будут не видны.
        $leftovers = array_filter(
            $this->dependentLeftovers(),
            static fn (int $count): bool => $count > 0,
        );

        if ($leftovers !== []) {
            return $leftovers;
        }

        DB::delete("DELETE FROM delivery_orders WHERE number LIKE 'BENCH-%'");
        DB::delete("DELETE FROM users WHERE email LIKE '%@benchmark.local'");
        DB::delete('DELETE FROM service_zones WHERE code = CAST(? AS text)', [self::ZONE_CODE]);

        return array_filter(
            $this->rootLeftovers(),
            static fn (int $count): bool => $count > 0,
        );
    }

    /**
     * Заказы одним INSERT: время заявки распределено по 90 дням, отчёт — за 30.
     */
    private function seedOrders(BenchmarkOptions $options): void
    {
        $datasetFrom = $options->to->copy()->subDays(self::DATASET_DAYS);

        DB::insert(
            <<<'SQL'
                WITH gen AS (
                    SELECT CAST(? AS timestamptz) AS dataset_from,
                           CAST(? AS timestamptz) AS period_to,
                           CAST(? AS integer) AS orders,
                           CAST(? AS text) AS seed
                ),
                rows AS (
                    SELECT g.n,
                           gen.seed,
                           gen.dataset_from
                               + (gen.period_to - gen.dataset_from) * (g.n::float8 / gen.orders) AS requested_at
                    FROM generate_series(1, (SELECT orders FROM gen)) AS g(n)
                    CROSS JOIN gen
                )
                INSERT INTO delivery_orders (
                    number, tracking_code, sender_id, zone_id, status, transport_type, light_vehicle_kind,
                    pickup_address, dropoff_address, parcel, distance_km, price_amount, price_currency,
                    price_breakdown, eta_minutes, requested_at, cancelled_at, created_at, updated_at
                )
                SELECT
                    'BENCH-' || lpad(r.n::text, 6, '0'),
                    'BENCH-' || substr(md5('tracking:' || r.seed || ':' || r.n::text), 1, 26),
                    sender.id,
                    zone.id,
                    CASE WHEN r.n % 17 = 0 THEN 'cancelled' WHEN r.n % 11 = 0 THEN 'created' ELSE 'delivered' END,
                    CASE r.n % 3 WHEN 0 THEN 'on_foot' WHEN 1 THEN 'light' ELSE 'car' END,
                    CASE WHEN r.n % 3 = 1 THEN 'bicycle' ELSE NULL END,
                    jsonb_build_object('address', 'BENCH pickup ' || r.n),
                    jsonb_build_object('address', 'BENCH dropoff ' || r.n),
                    jsonb_build_object('weight_kg', 1),
                    (1 + r.n % 20)::numeric,
                    (1000 + (r.n * 37) % 90000)::bigint,
                    'RUB',
                    CAST('{}' AS jsonb),
                    (10 + r.n % 100)::smallint,
                    r.requested_at,
                    CASE WHEN r.n % 17 = 0 THEN r.requested_at + interval '10 minutes' ELSE NULL END,
                    now(), now()
                FROM rows r
                JOIN (
                    SELECT (row_number() OVER (ORDER BY id) - 1)::integer AS ord, id
                    FROM users
                    WHERE email LIKE '%@benchmark.local'
                      AND role = 'sender'
                ) AS sender ON sender.ord = (r.n % CAST(? AS integer))::integer
                CROSS JOIN (SELECT id FROM service_zones WHERE code = CAST(? AS text)) AS zone
                SQL,
            [
                $datasetFrom->format('Y-m-d H:i:sP'),
                $options->to->format('Y-m-d H:i:sP'),
                $options->orders,
                (string) $options->seed,
                self::SENDERS,
                self::ZONE_CODE,
            ],
        );
    }

    /**
     * Назначения: вручения и неудачные попытки части заказов.
     *
     * `delivered_at` лежит внутри отчётного периода. Неудачные попытки нужны,
     * чтобы таблица не состояла целиком из вручений и частичный индекс по
     * `delivered_at` имел осмысленную селективность.
     */
    private function seedAssignments(BenchmarkOptions $options): void
    {
        DB::insert(
            <<<'SQL'
                WITH params AS (
                    SELECT CAST(? AS timestamptz) AS period_from,
                           CAST(? AS timestamptz) AS period_to
                ),
                couriers AS (
                    SELECT (row_number() OVER (ORDER BY id) - 1)::integer AS ord, id
                    FROM users
                    WHERE email LIKE '%@benchmark.local'
                      AND role = 'courier'
                ),
                targets AS (
                    SELECT o.id AS order_id,
                           p.period_from
                               + (p.period_to - p.period_from) * ((o.id % 997)::float8 / 997.0) AS delivered_at,
                           (o.id % (SELECT count(*) FROM couriers))::integer AS ord
                    FROM delivery_orders o
                    CROSS JOIN params p
                    WHERE o.number LIKE 'BENCH-%'
                      AND (o.id % CAST(? AS integer)) < CAST(? AS integer)
                )
                INSERT INTO assignments (
                    order_id, courier_id, status, strategy, distance_to_pickup_km,
                    assigned_at, accepted_at, picked_up_at, delivered_at, created_at, updated_at
                )
                SELECT
                    t.order_id,
                    c.id,
                    'delivered',
                    'nearest',
                    1.5,
                    t.delivered_at - interval '40 minutes',
                    t.delivered_at - interval '35 minutes',
                    t.delivered_at - interval '20 minutes',
                    t.delivered_at,
                    now(), now()
                FROM targets t
                JOIN couriers c ON c.ord = t.ord
                SQL,
            [
                $options->from->format('Y-m-d H:i:sP'),
                $options->to->format('Y-m-d H:i:sP'),
                self::DELIVERED_EVERY,
                self::DELIVERED_LIMIT,
            ],
        );

        DB::insert(
            <<<'SQL'
                WITH params AS (
                    SELECT CAST(? AS timestamptz) AS period_from,
                           CAST(? AS timestamptz) AS period_to
                ),
                couriers AS (
                    SELECT (row_number() OVER (ORDER BY id) - 1)::integer AS ord, id
                    FROM users
                    WHERE email LIKE '%@benchmark.local'
                      AND role = 'courier'
                ),
                targets AS (
                    SELECT o.id AS order_id,
                           p.period_from
                               + (p.period_to - p.period_from) * ((o.id % 991)::float8 / 991.0) AS failed_at,
                           (o.id % (SELECT count(*) FROM couriers))::integer AS ord
                    FROM delivery_orders o
                    CROSS JOIN params p
                    WHERE o.number LIKE 'BENCH-%'
                      AND (o.id % CAST(? AS integer)) >= CAST(? AS integer)
                )
                INSERT INTO assignments (
                    order_id, courier_id, status, strategy, distance_to_pickup_km,
                    assigned_at, failed_at, failure_reason, created_at, updated_at
                )
                SELECT
                    t.order_id,
                    c.id,
                    'failed',
                    'nearest',
                    1.5,
                    t.failed_at - interval '15 minutes',
                    t.failed_at,
                    'courier_unavailable',
                    now(), now()
                FROM targets t
                JOIN couriers c ON c.ord = t.ord
                SQL,
            [
                $options->from->format('Y-m-d H:i:sP'),
                $options->to->format('Y-m-d H:i:sP'),
                self::DELIVERED_EVERY,
                self::DELIVERED_LIMIT,
            ],
        );
    }

    /**
     * Смены курьеров: по 10 непересекающихся смен на курьера.
     */
    private function seedShifts(BenchmarkOptions $options): void
    {
        DB::insert(
            <<<'SQL'
                WITH params AS (
                    SELECT CAST(? AS timestamptz) AS period_from,
                           CAST(? AS timestamptz) AS period_to
                ),
                couriers AS (
                    SELECT (row_number() OVER (ORDER BY id) - 1)::integer AS ord, id
                    FROM users
                    WHERE email LIKE '%@benchmark.local'
                      AND role = 'courier'
                )
                INSERT INTO courier_shifts (courier_id, zone_id, started_at, ended_at, created_at, updated_at)
                SELECT
                    c.id,
                    NULL,
                    p.period_from
                        + ((c.ord % 13)::float8 / 13.0) * (p.period_to - p.period_from)
                        + (s.g * interval '3 days'),
                    p.period_from
                        + ((c.ord % 13)::float8 / 13.0) * (p.period_to - p.period_from)
                        + (s.g * interval '3 days')
                        + interval '8 hours',
                    now(), now()
                FROM couriers c
                CROSS JOIN params p
                CROSS JOIN generate_series(0, CAST(? AS integer)) AS s(g)
                SQL,
            [
                $options->from->format('Y-m-d H:i:sP'),
                $options->to->format('Y-m-d H:i:sP'),
                self::SHIFTS_PER_COURIER - 1,
            ],
        );
    }

    /**
     * Неудалённые строки, зависящие от владельцев набора.
     *
     * @return array<string, int>
     */
    private function dependentLeftovers(): array
    {
        $row = DB::selectOne(
            <<<'SQL'
                SELECT
                    (SELECT count(*) FROM assignments
                     WHERE order_id IN (SELECT id FROM delivery_orders WHERE number LIKE 'BENCH-%')
                        OR courier_id IN (SELECT id FROM users WHERE email LIKE '%@benchmark.local')) AS assignments,
                    (SELECT count(*) FROM courier_shifts
                     WHERE courier_id IN (SELECT id FROM users WHERE email LIKE '%@benchmark.local')) AS courier_shifts
                SQL,
        );

        $data = (array) $row;

        return [
            'assignments' => (int) $data['assignments'],
            'courier_shifts' => (int) $data['courier_shifts'],
        ];
    }

    /**
     * Строки-владельцы набора после удаления.
     *
     * @return array<string, int>
     */
    private function rootLeftovers(): array
    {
        $row = DB::selectOne(
            <<<'SQL'
                SELECT
                    (SELECT count(*) FROM delivery_orders WHERE number LIKE 'BENCH-%') AS delivery_orders,
                    (SELECT count(*) FROM users WHERE email LIKE '%@benchmark.local') AS users,
                    (SELECT count(*) FROM service_zones WHERE code = CAST('BENCH-ZONE' AS text)) AS service_zones
                SQL,
        );

        $data = (array) $row;

        return [
            'delivery_orders' => (int) $data['delivery_orders'],
            'users' => (int) $data['users'],
            'service_zones' => (int) $data['service_zones'],
        ];
    }

    /**
     * Дробное зерно для setseed: диапазон -1…1.
     */
    private function seedFraction(int $seed): float
    {
        $bounded = $seed % 1000;

        return $bounded / 1000;
    }
}
