<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Dispatch\AssignmentStrategy;
use App\Domain\Dispatch\FailureReason;
use App\Domain\Identity\LightVehicleKind;
use App\Domain\Identity\Role;
use App\Domain\Identity\TransportType;
use App\Domain\Order\OrderStatus;
use App\Domain\Tracking\TrackingPointSource;
use App\Models\Dispatch\Assignment;
use App\Models\Dispatch\CourierShift;
use App\Models\Geography\ServiceZone;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OrderStatusHistory;
use App\Models\Tracking\CourierLastPosition;
use App\Models\User;
use Generator;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Демонстрационный набор для показа и ручной проверки.
 *
 * Создаёт учётные записи всех ролей, дополнительные зоны и двадцать заказов
 * во всех статусах с историей, назначениями, сменами и геоточками. Набор
 * идемпотентен: повторный запуск обновляет записи по естественным ключам
 * (почта, код зоны, номер заказа) и не создаёт дубликатов.
 *
 * Побочные эффекты отключены (WithoutModelEvents): события Outbox
 * и уведомления не публикуются, кэш трекинга не сбрасывается.
 */
final class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * База зарезервированного блока номеров демо-заказов: 900001…900020.
     *
     * Реальные заказы берут номера из последовательности order_number_seq
     * начиная с 1, поэтому демо-блок не может с ней пересечься.
     */
    private const DEMO_SEQUENCE_BASE = 900000;

    /**
     * Демо-зоны стоят рядом с прямоугольником msk-demo и не перекрывают его.
     *
     * @var array<string, array{south_west: array{0: float, 1: float}, north_east: array{0: float, 1: float}}>
     */
    private const ZONE_BOUNDARIES = [
        'msk-north' => ['south_west' => [55.90, 37.40], 'north_east' => [56.05, 37.80]],
        'msk-south' => ['south_west' => [55.40, 37.40], 'north_east' => [55.60, 37.80]],
        'msk-east' => ['south_west' => [55.60, 37.80], 'north_east' => [55.90, 38.10]],
        'msk-west' => ['south_west' => [55.60, 37.10], 'north_east' => [55.90, 37.40]],
        'msk-center' => ['south_west' => [55.74, 37.58], 'north_east' => [55.78, 37.66]],
        'msk-outside-a' => ['south_west' => [56.20, 37.20], 'north_east' => [56.40, 37.80]],
        'msk-outside-b' => ['south_west' => [55.10, 37.20], 'north_east' => [55.30, 37.80]],
    ];

    /**
     * План заказов: статус, транспорт и курьер подобраны так, чтобы выполнялись
     * ограничения базы (одно активное назначение на курьера) и правила домена.
     *
     * Активных назначений ровно три (assigned, accepted, picked_up) — по одному
     * на курьера, а courier@, courier3@ и courier6@ несут только терминальные
     * назначения. Поэтому у этих трёх курьеров открытая смена без активного
     * назначения, и подбор кандидатов на созданный заказ не пуст.
     *
     * @var list<array{status: string, transport: string, kind: string|null, courier: string|null}>
     */
    private const ORDER_PLAN = [
        // Созданный light-заказ: на него диспетчер смотрит кандидатов.
        ['status' => 'created', 'transport' => 'light', 'kind' => 'bicycle', 'courier' => null],
        ['status' => 'created', 'transport' => 'on_foot', 'kind' => null, 'courier' => null],
        ['status' => 'created', 'transport' => 'car', 'kind' => null, 'courier' => null],
        // Ровно три активных назначения — по одному на курьера.
        ['status' => 'assigned', 'transport' => 'on_foot', 'kind' => null, 'courier' => 'courier2@courier.test'],
        ['status' => 'accepted', 'transport' => 'car', 'kind' => null, 'courier' => 'courier5@courier.test'],
        ['status' => 'picked_up', 'transport' => 'light', 'kind' => 'scooter', 'courier' => 'courier4@courier.test'],
        // Терминальные назначения остальных курьеров: они остаются свободными.
        ['status' => 'delivered', 'transport' => 'light', 'kind' => 'bicycle', 'courier' => 'courier@courier.test'],
        ['status' => 'delivered', 'transport' => 'on_foot', 'kind' => null, 'courier' => 'courier6@courier.test'],
        ['status' => 'delivered', 'transport' => 'light', 'kind' => 'e_bike', 'courier' => 'courier3@courier.test'],
        ['status' => 'delivered', 'transport' => 'car', 'kind' => null, 'courier' => 'courier5@courier.test'],
        ['status' => 'delivered', 'transport' => 'light', 'kind' => 'scooter', 'courier' => 'courier4@courier.test'],
        ['status' => 'delivered', 'transport' => 'light', 'kind' => 'bicycle', 'courier' => 'courier@courier.test'],
        ['status' => 'delivered', 'transport' => 'on_foot', 'kind' => null, 'courier' => 'courier2@courier.test'],
        ['status' => 'delivered', 'transport' => 'light', 'kind' => 'e_bike', 'courier' => 'courier3@courier.test'],
        ['status' => 'closed', 'transport' => 'light', 'kind' => 'bicycle', 'courier' => 'courier@courier.test'],
        ['status' => 'closed', 'transport' => 'on_foot', 'kind' => null, 'courier' => 'courier6@courier.test'],
        ['status' => 'cancelled', 'transport' => 'light', 'kind' => 'bicycle', 'courier' => null],
        ['status' => 'cancelled', 'transport' => 'car', 'kind' => null, 'courier' => null],
        ['status' => 'cancelled', 'transport' => 'on_foot', 'kind' => null, 'courier' => null],
        ['status' => 'failed', 'transport' => 'light', 'kind' => 'e_bike', 'courier' => 'courier3@courier.test'],
    ];

    /**
     * Последние позиции свободных курьеров: без них подбор не знает расстояния
     * до забора и времени подхода, и кандидат получает худший ранг.
     *
     * Позиции намеренно разводят правила на созданном light-заказе
     * (забор 55.7576/37.6054, удлинение маршрута 1.3):
     * courier@ ближе — 1.30 км на bicycle 15 км/ч, подход 6 минут;
     * courier3@ дальше — 1.55 км на e_bike 20 км/ч, подход 5 минут.
     * Поэтому «ближайший» ставит первым courier@, «лучший ETA» — courier3@.
     *
     * @var array<string, array{0: float, 1: float}>
     */
    private const COURIER_POSITIONS = [
        'courier@courier.test' => [55.766593, 37.6054],
        'courier3@courier.test' => [55.746877, 37.6054],
        'courier6@courier.test' => [55.7515, 37.5938],
    ];

    /**
     * Путь переходов статусов для каждого итогового статуса (ЧТЗ 5.2).
     *
     * @var array<string, list<string>>
     */
    private const STATUS_PATH = [
        'created' => ['created'],
        'assigned' => ['created', 'assigned'],
        'accepted' => ['created', 'assigned', 'accepted'],
        'picked_up' => ['created', 'assigned', 'accepted', 'picked_up'],
        'delivered' => ['created', 'assigned', 'accepted', 'picked_up', 'delivered'],
        'closed' => ['created', 'assigned', 'accepted', 'picked_up', 'delivered', 'closed'],
        'cancelled' => ['created', 'cancelled'],
        'failed' => ['created', 'assigned', 'accepted', 'picked_up', 'failed'],
    ];

    /**
     * Отправители по кругу: заказы распределены между тремя аккаунтами.
     *
     * @var list<string>
     */
    private const SENDER_EMAILS = [
        'sender@courier.test',
        'sender2@courier.test',
        'sender3@courier.test',
    ];

    /**
     * @var array<string, User>
     */
    private array $users = [];

    /**
     * @var array<string, ServiceZone>
     */
    private array $zones = [];

    public function run(): void
    {
        $this->ensureEnvironmentIsAllowed();

        DB::transaction(function (): void {
            $this->seedAccounts();
            $this->seedZones();
            $this->seedShifts();
            $this->seedLastPositions();
            $this->seedOrders();
        });
    }

    /**
     * Демо-данные допустимы только в перечисленных окружениях.
     */
    private function ensureEnvironmentIsAllowed(): void
    {
        /** @var list<string> $allowed */
        $allowed = config('demo.environments', ['local', 'testing']);

        if (app()->environment($allowed)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Демонстрационные данные нельзя наполнять в окружении %s: разрешены %s.',
            app()->environment(),
            implode(', ', $allowed),
        ));
    }

    /**
     * Учётные записи всех ролей с профилями.
     */
    private function seedAccounts(): void
    {
        /** @var string $password */
        $password = (string) config('demo.password', 'password');

        /** @var list<array{email: string, name: string, role: string, transport?: string, kind?: string}> $accounts */
        $accounts = config('demo.accounts', []);

        foreach ($accounts as $account) {
            $user = User::query()->firstOrNew(['email' => $account['email']]);

            $user->forceFill([
                'name' => $account['name'],
                'email' => $account['email'],
                'password' => Hash::make($password),
                'role' => Role::from($account['role']),
                'email_verified_at' => now(),
            ])->save();

            match ($user->role) {
                Role::Sender => $user->senderProfile()->updateOrCreate([], [
                    'phone' => null,
                    'company' => null,
                    'default_address' => null,
                ]),
                Role::Courier => $user->courierProfile()->updateOrCreate([], [
                    'transport_type' => TransportType::from($account['transport'] ?? 'on_foot'),
                    'light_vehicle_kind' => isset($account['kind'])
                        ? LightVehicleKind::from($account['kind'])
                        : null,
                    'is_active' => true,
                ]),
                Role::Dispatcher, Role::Admin => null,
            };

            $this->users[$account['email']] = $user->refresh();
        }
    }

    /**
     * Демо-зона из GeographyDemoSeeder и зоны вокруг неё.
     */
    private function seedZones(): void
    {
        $this->seedHomeZone();

        /** @var list<array{code: string, name: string, base_fee_amount: int}> $zones */
        $zones = config('demo.zones', []);

        foreach ($zones as $zone) {
            $this->zones[$zone['code']] = ServiceZone::query()->updateOrCreate(
                ['code' => $zone['code']],
                [
                    'name' => $zone['name'],
                    'boundary' => $this->boundary($zone['code']),
                    'base_fee_amount' => $zone['base_fee_amount'],
                    'currency' => 'RUB',
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * Зона со справочником адресов: её создаёт GeographyDemoSeeder.
     */
    private function seedHomeZone(): void
    {
        /** @var string $code */
        $code = (string) config('demo.home_zone_code', 'msk-demo');

        $zone = ServiceZone::query()->where('code', $code)->first();

        if ($zone === null) {
            throw new RuntimeException(sprintf(
                'Демо-зона %s не найдена: сначала выполните GeographyDemoSeeder.',
                $code,
            ));
        }

        $this->zones[$code] = $zone;
    }

    /**
     * Граница зоны в формате домена ServiceArea.
     *
     * @return array{type: string, south_west: array{latitude: float, longitude: float}, north_east: array{latitude: float, longitude: float}}
     */
    private function boundary(string $code): array
    {
        $bounds = self::ZONE_BOUNDARIES[$code] ?? self::ZONE_BOUNDARIES['msk-outside-b'];

        return [
            'type' => 'rectangle',
            'south_west' => ['latitude' => $bounds['south_west'][0], 'longitude' => $bounds['south_west'][1]],
            'north_east' => ['latitude' => $bounds['north_east'][0], 'longitude' => $bounds['north_east'][1]],
        ];
    }

    /**
     * Смены курьеров: активные заказы требуют открытой смены, остальные — закрытой.
     */
    private function seedShifts(): void
    {
        $today = now();

        foreach ($this->users as $email => $user) {
            if ($user->role !== Role::Courier) {
                continue;
            }

            $shift = CourierShift::query()->where('courier_id', $user->getKey())->first()
                ?? new CourierShift;

            // Курьер 4 в плане заказов везёт заказ в работе, но его смена уже закрыта:
            // так видно, что статус назначения и смена живут отдельно.
            $closed = $email === 'courier4@courier.test';

            $shift->forceFill([
                'courier_id' => $user->getKey(),
                'zone_id' => $this->zones['msk-demo']->getKey(),
                'started_at' => $today->copy()->startOfDay()->addHours(8),
                'ended_at' => $closed ? $today->copy()->startOfDay()->addHours(18) : null,
            ])->save();
        }
    }

    /**
     * Последние позиции свободных курьеров: по одной строке на курьера.
     *
     * Позиция обновляется по курьеру, поэтому повторный запуск не создаёт дубликатов.
     */
    private function seedLastPositions(): void
    {
        $recordedAt = now();

        foreach (self::COURIER_POSITIONS as $email => [$latitude, $longitude]) {
            CourierLastPosition::query()->updateOrCreate(
                ['courier_id' => $this->users[$email]->getKey()],
                [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'recorded_at' => $recordedAt,
                ],
            );
        }
    }

    /**
     * Заказы со всеми статусами, историей, назначениями и геоточками.
     */
    private function seedOrders(): void
    {
        $plan = array_slice(self::ORDER_PLAN, 0, (int) config('demo.orders_total', 20));
        $periodStart = now()->startOfMonth();
        $year = (int) now()->format('Y');

        foreach ($plan as $index => $item) {
            $requestedAt = $periodStart->copy()
                ->addDays(min($index, max(0, now()->day - 1)))
                ->addHours(9 + ($index % 8));

            $order = $this->seedOrder($index, $item, $requestedAt, $year);

            $this->seedHistory($order, $item['status'], $requestedAt);

            if ($item['courier'] !== null) {
                $this->seedAssignment($order, $item, $requestedAt);
            }
        }
    }

    /**
     * Строка заказа с фиксированным номером и кодом трекинга.
     *
     * @param array{status: string, transport: string, kind: string|null, courier: string|null} $item
     */
    private function seedOrder(int $index, array $item, Carbon $requestedAt, int $year): DeliveryOrder
    {
        $number = sprintf('CRG-%d-%06d', $year, self::DEMO_SEQUENCE_BASE + $index + 1);
        $trackingCode = sprintf('trk_demo%08d', $index + 1);

        $sender = $this->users[self::SENDER_EMAILS[$index % count(self::SENDER_EMAILS)]];
        $transport = TransportType::from($item['transport']);
        $price = 19400 + $index * 900;

        $order = DeliveryOrder::query()->firstOrNew(['number' => $number]);

        $order->forceFill([
            'number' => $number,
            'tracking_code' => $order->exists ? $order->tracking_code : $trackingCode,
            'sender_id' => $sender->getKey(),
            'zone_id' => $this->zones['msk-demo']->getKey(),
            'status' => OrderStatus::from($item['status']),
            'transport_type' => $transport,
            'light_vehicle_kind' => $item['kind'] === null ? null : LightVehicleKind::from($item['kind']),
            'pickup_address' => [
                'district' => 'Тверской',
                'street' => 'Тверская улица',
                'house' => (string) (1 + $index),
                'latitude' => 55.7576,
                'longitude' => 37.6054,
            ],
            'dropoff_address' => [
                'district' => 'Арбат',
                'street' => 'Улица Арбат',
                'house' => (string) (10 + $index),
                'latitude' => 55.7515,
                'longitude' => 37.5938,
            ],
            'parcel' => [
                'weight_grams' => 1000 + $index * 100,
                'length_cm' => 30,
                'width_cm' => 20,
                'height_cm' => 10,
            ],
            'distance_km' => round(1.2 + $index * 0.3, 2),
            'price_amount' => $price,
            'price_currency' => 'RUB',
            'price_breakdown' => [
                ['code' => 'base', 'label' => 'Базовая ставка', 'amount' => 14900],
                ['code' => 'distance', 'label' => 'Расстояние', 'amount' => $price - 14900],
                ['code' => 'surge', 'label' => 'Надбавка за час пик', 'amount' => 0],
            ],
            'eta_minutes' => 15 + $index,
            'requested_at' => $requestedAt,
            'cancelled_at' => $item['status'] === 'cancelled' ? $requestedAt->copy()->addHours(1) : null,
        ])->save();

        return $order;
    }

    /**
     * История статусов ровно по пути переходов: последняя запись — текущий статус.
     */
    private function seedHistory(DeliveryOrder $order, string $status, Carbon $requestedAt): void
    {
        $path = self::STATUS_PATH[$status] ?? ['created'];
        $author = $this->users['dispatcher@courier.test'];

        $existing = OrderStatusHistory::query()->where('order_id', $order->getKey())->count();

        // Запись истории неизменяема: при совпадении состава ничего не переписываем.
        if ($existing === count($path)) {
            return;
        }

        // Служебная очистка набора допустима: строки принадлежат демо-заказам.
        OrderStatusHistory::query()->where('order_id', $order->getKey())->delete();

        $step = 0;
        $previous = null;

        foreach ($path as $toStatus) {
            OrderStatusHistory::query()->forceCreate([
                'order_id' => $order->getKey(),
                'from_status' => $previous,
                'to_status' => OrderStatus::from($toStatus),
                'changed_by' => $previous === null ? null : $author->getKey(),
                'reason' => null,
                'created_at' => $requestedAt->copy()->addMinutes($step * 25),
            ]);

            $previous = OrderStatus::from($toStatus);
            $step++;
        }
    }

    /**
     * Назначение курьера и геоточки для заказов, которые уже назначены.
     *
     * @param array{status: string, transport: string, kind: string|null, courier: string|null} $item
     */
    private function seedAssignment(DeliveryOrder $order, array $item, Carbon $requestedAt): void
    {
        $courier = $this->users[(string) $item['courier']];
        $assignedAt = $requestedAt->copy()->addMinutes(30);

        $status = match ($item['status']) {
            'assigned' => AssignmentStatus::Assigned,
            'accepted' => AssignmentStatus::Accepted,
            'picked_up' => AssignmentStatus::PickedUp,
            'delivered', 'closed' => AssignmentStatus::Delivered,
            'failed' => AssignmentStatus::Failed,
            default => AssignmentStatus::Assigned,
        };

        $assignment = Assignment::query()->firstOrNew([
            'order_id' => $order->getKey(),
            'courier_id' => $courier->getKey(),
        ]);

        $assignment->forceFill([
            'order_id' => $order->getKey(),
            'courier_id' => $courier->getKey(),
            'status' => $status,
            'strategy' => AssignmentStrategy::Nearest,
            'distance_to_pickup_km' => 1.5,
            'assigned_at' => $assignedAt,
            'accepted_at' => $status === AssignmentStatus::Assigned ? null : $assignedAt->copy()->addMinutes(5),
            'picked_up_at' => in_array($status, [
                AssignmentStatus::PickedUp,
                AssignmentStatus::Delivered,
                AssignmentStatus::Failed,
            ], true) ? $assignedAt->copy()->addMinutes(15) : null,
            'delivered_at' => $status === AssignmentStatus::Delivered
                ? $assignedAt->copy()->addMinutes(35)
                : null,
            'failed_at' => $status === AssignmentStatus::Failed
                ? $assignedAt->copy()->addMinutes(35)
                : null,
            'cancelled_at' => null,
            'failure_reason' => $status === AssignmentStatus::Failed ? FailureReason::RecipientUnavailable : null,
            'failure_comment' => $status === AssignmentStatus::Failed ? 'Получатель не открыл дверь' : null,
        ])->save();

        if ($status === AssignmentStatus::PickedUp) {
            $this->seedTrackingPoints($assignment, $assignedAt);
        }
    }

    /**
     * Геоточки пути: три точки вдоль маршрута забора и доставки.
     */
    private function seedTrackingPoints(Assignment $assignment, Carbon $assignedAt): void
    {
        // Геоточки демо-назначения пересобираются целиком: у точки нет
        // естественного ключа кроме времени, а время хранится в UTC.
        $assignment->points()->delete();

        foreach ($this->trackingPoints($assignedAt) as [$latitude, $longitude, $recordedAt]) {
            $assignment->points()->create([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'recorded_at' => $recordedAt,
                'source' => TrackingPointSource::Courier,
            ]);
        }
    }

    /**
     * @return Generator<int, array{0: float, 1: float, 2: Carbon}>
     */
    private function trackingPoints(Carbon $assignedAt): Generator
    {
        yield [55.7558, 37.6173, $assignedAt->copy()->addMinutes(18)];
        yield [55.7530, 37.6100, $assignedAt->copy()->addMinutes(21)];
        yield [55.7515, 37.5938, $assignedAt->copy()->addMinutes(24)];
    }
}
