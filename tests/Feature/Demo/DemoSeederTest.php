<?php

declare(strict_types=1);

use App\Actions\Dispatch\AssignCourier;
use App\Actions\Dispatch\FindCouriers;
use App\Domain\Dispatch\AssignmentStatus;
use App\Domain\Identity\Role;
use App\Domain\Identity\TransportType;
use App\Domain\Order\OrderNumber;
use App\Domain\Order\OrderStatus;
use App\Models\Dispatch\Assignment;
use App\Models\Dispatch\CourierShift;
use App\Models\Geography\ServiceZone;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OrderStatusHistory;
use App\Models\Tracking\CourierLastPosition;
use App\Models\Tracking\TrackingPoint;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Database\Seeders\GeographyDemoSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Наполняет демонстрационный набор: зона и адреса нужны DemoSeeder.
 */
function seedDemo(): void
{
    app(GeographyDemoSeeder::class)->run();
    app(DemoSeeder::class)->run();
}

/**
 * Идентификаторы свободных курьеров: открытая смена и нет активного назначения.
 *
 * @return list<int>
 */
function freeCourierIds(): array
{
    $ids = DB::table('users')
        ->join('courier_shifts', 'courier_shifts.courier_id', '=', 'users.id')
        ->whereNull('courier_shifts.ended_at')
        ->whereNotExists(static function ($query): void {
            $query->selectRaw('1')
                ->from('assignments')
                ->whereColumn('assignments.courier_id', 'users.id')
                ->whereIn('assignments.status', AssignmentStatus::activeValues());
        })
        ->orderBy('users.id')
        ->pluck('users.id')
        ->all();

    return array_map(static fn ($id): int => (int) $id, $ids);
}

it('создаёт учётные записи всех ролей с профилями', function (): void {
    seedDemo();

    expect(User::query()->count())->toBe(12)
        ->and(User::query()->where('role', Role::Sender->value)->count())->toBe(3)
        ->and(User::query()->where('role', Role::Dispatcher->value)->count())->toBe(2)
        ->and(User::query()->where('role', Role::Admin->value)->count())->toBe(1)
        ->and(User::query()->where('role', Role::Courier->value)->count())->toBe(6)
        ->and(User::query()->whereNotNull('email_verified_at')->count())->toBe(12);

    $transports = User::query()
        ->where('role', Role::Courier->value)
        ->with('courierProfile')
        ->get()
        ->map(static fn (User $user): string => $user->courierProfile?->transport_type instanceof TransportType
            ? $user->courierProfile->transport_type->value
            : '')
        ->sort()
        ->values()
        ->all();

    expect($transports)->toBe(['car', 'light', 'light', 'light', 'on_foot', 'on_foot']);
});

it('сохраняет адреса демо-учётных записей из README', function (): void {
    seedDemo();

    $this->assertDatabaseHas('users', ['email' => 'sender@courier.test', 'role' => Role::Sender->value]);
    $this->assertDatabaseHas('users', ['email' => 'courier@courier.test', 'role' => Role::Courier->value]);
    $this->assertDatabaseHas('users', ['email' => 'dispatcher@courier.test', 'role' => Role::Dispatcher->value]);
    $this->assertDatabaseHas('users', ['email' => 'admin@courier.test', 'role' => Role::Admin->value]);
});

it('создаёт дополнительные зоны рядом с демонстрационной', function (): void {
    seedDemo();

    expect(ServiceZone::query()->where('code', 'msk-demo')->exists())->toBeTrue()
        ->and(ServiceZone::query()->count())->toBe(8);
});

it('создаёт двадцать заказов во всех статусах', function (): void {
    seedDemo();

    expect(DeliveryOrder::query()->count())->toBe(20);

    foreach (OrderStatus::cases() as $status) {
        expect(DeliveryOrder::query()->where('status', $status->value)->count())
            ->toBeGreaterThan(0);
    }
});

it('завершает историю текущим статусом и повторяет путь переходов', function (): void {
    seedDemo();

    foreach (DeliveryOrder::query()->get() as $order) {
        $history = OrderStatusHistory::query()
            ->where('order_id', $order->getKey())
            ->orderBy('id')
            ->get();

        expect($history)->not->toBeEmpty();

        $last = $history->last();
        $first = $history->first();

        expect($last?->to_status)->toBe($order->status)
            ->and($first?->from_status)->toBeNull();

        foreach ($history as $index => $entry) {
            if ($index === 0) {
                continue;
            }

            expect($entry->from_status)->toBe($history[$index - 1]?->to_status);
        }
    }
});

it('назначает курьера заказам, которые уже в работе', function (): void {
    seedDemo();

    foreach (DeliveryOrder::query()->whereNotIn('status', ['created', 'cancelled'])->get() as $order) {
        expect(Assignment::query()->where('order_id', $order->getKey())->exists())->toBeTrue();
    }

    expect(Assignment::query()->where('status', AssignmentStatus::Failed->value)->count())->toBe(1)
        ->and(TrackingPoint::query()->count())->toBeGreaterThan(0);
});

it('оставляет у курьера не больше одного активного назначения', function (): void {
    seedDemo();

    $active = DB::table('assignments')
        ->whereIn('status', [
            AssignmentStatus::Assigned->value,
            AssignmentStatus::Accepted->value,
            AssignmentStatus::PickedUp->value,
        ])
        ->select('courier_id', DB::raw('count(*) as total'))
        ->groupBy('courier_id')
        ->get();

    foreach ($active as $row) {
        expect((int) $row->total)->toBe(1);
    }

    expect($active)->toHaveCount(3);
});

it('оставляет трёх свободных курьеров с открытой сменой', function (): void {
    seedDemo();

    $expected = User::query()
        ->whereIn('email', [
            'courier@courier.test',
            'courier3@courier.test',
            'courier6@courier.test',
        ])
        ->orderBy('id')
        ->pluck('id')
        ->map(static fn ($id): int => (int) $id)
        ->all();

    expect(freeCourierIds())->toBe($expected)
        ->and($expected)->toHaveCount(3);
});

it('даёт каждому свободному курьеру последнюю позицию', function (): void {
    seedDemo();

    $free = freeCourierIds();

    expect($free)->toHaveCount(3)
        ->and(CourierLastPosition::query()->count())->toBe(3);

    foreach ($free as $courierId) {
        expect(CourierLastPosition::query()->where('courier_id', $courierId)->exists())->toBeTrue();
    }
});

it('подбирает кандидатов на созданный light-заказ и разводит правила', function (): void {
    seedDemo();

    $order = DeliveryOrder::query()
        ->where('status', OrderStatus::Created->value)
        ->where('transport_type', TransportType::Light->value)
        ->orderBy('number')
        ->firstOrFail();

    $nearest = app(FindCouriers::class)->handle($order, 'nearest')->candidates();
    $bestEta = app(FindCouriers::class)->handle($order, 'best_eta')->candidates();

    $nearId = (int) User::query()->where('email', 'courier@courier.test')->value('id');
    $fastId = (int) User::query()->where('email', 'courier3@courier.test')->value('id');

    expect(count($nearest))->toBeGreaterThanOrEqual(2)
        ->and(count($bestEta))->toBeGreaterThanOrEqual(2)
        ->and($nearest[0]->courierId())->toBe($nearId)
        ->and($bestEta[0]->courierId())->toBe($fastId)
        ->and($nearest[0]->distanceToPickupKm())->toBeLessThan((float) $nearest[1]->distanceToPickupKm())
        ->and($bestEta[0]->pickupApproachMinutes())->toBeLessThan((int) $bestEta[1]->pickupApproachMinutes());
});

it('назначает курьера на демо-заказ без ошибки формата номера', function (): void {
    seedDemo();

    $dispatcher = User::query()->where('email', 'dispatcher@courier.test')->firstOrFail();
    $order = DeliveryOrder::query()
        ->where('status', OrderStatus::Created->value)
        ->where('transport_type', TransportType::Light->value)
        ->orderBy('number')
        ->firstOrFail();

    $before = Assignment::query()->count();

    // Регрессия: восстановление агрегата по демо-номеру не должно падать.
    $assignment = app(AssignCourier::class)->handle($order, $dispatcher, null, null);

    expect($assignment->order_id)->toBe($order->getKey())
        ->and($assignment->status)->toBe(AssignmentStatus::Assigned)
        ->and($order->refresh()->status)->toBe(OrderStatus::Assigned)
        ->and(Assignment::query()->count())->toBe($before + 1);
});

it('выдаёт демо-заказам номера в доменном формате', function (): void {
    seedDemo();

    $numbers = DeliveryOrder::query()->orderBy('number')->pluck('number');

    expect($numbers)->toHaveCount(20);

    foreach ($numbers as $number) {
        expect(OrderNumber::fromString((string) $number)->value())->toBe((string) $number);
    }
});

it('не дублирует последние позиции при повторном запуске', function (): void {
    seedDemo();

    $before = CourierLastPosition::query()->count();

    $this->seed(DemoSeeder::class);

    expect($before)->toBe(3)
        ->and(CourierLastPosition::query()->count())->toBe($before);
});

it('открывает смены курьеров без пересечений', function (): void {
    seedDemo();

    expect(CourierShift::query()->count())->toBe(6)
        ->and(CourierShift::query()->whereNull('ended_at')->count())->toBe(5);
});

it('повторный запуск не создаёт дубликатов', function (): void {
    seedDemo();

    $counts = [
        'users' => User::query()->count(),
        'zones' => ServiceZone::query()->count(),
        'orders' => DeliveryOrder::query()->count(),
        'history' => OrderStatusHistory::query()->count(),
        'assignments' => Assignment::query()->count(),
        'shifts' => CourierShift::query()->count(),
        'points' => TrackingPoint::query()->count(),
        'positions' => CourierLastPosition::query()->count(),
    ];

    $this->seed(DemoSeeder::class);

    expect(User::query()->count())->toBe($counts['users'])
        ->and(ServiceZone::query()->count())->toBe($counts['zones'])
        ->and(DeliveryOrder::query()->count())->toBe($counts['orders'])
        ->and(OrderStatusHistory::query()->count())->toBe($counts['history'])
        ->and(Assignment::query()->count())->toBe($counts['assignments'])
        ->and(CourierShift::query()->count())->toBe($counts['shifts'])
        ->and(TrackingPoint::query()->count())->toBe($counts['points'])
        ->and(CourierLastPosition::query()->count())->toBe($counts['positions']);
});

it('даёт одинаковые номера и коды при фиксированном времени', function (): void {
    Carbon::setTestNow('2026-11-06 10:00:00');

    try {
        seedDemo();

        $first = DeliveryOrder::query()->orderBy('number')->pluck('tracking_code', 'number')->all();

        // Повторный прогон обновляет набор по естественным ключам.
        $this->seed(DemoSeeder::class);

        $second = DeliveryOrder::query()->orderBy('number')->pluck('tracking_code', 'number')->all();

        expect($second)->toBe($first)
            ->and(array_key_first($first))->toBe('CRG-2026-900001');
    } finally {
        Carbon::setTestNow();
    }
});

it('не порождает событий и уведомлений', function (): void {
    seedDemo();

    // Локального журнала уведомлений в монолите нет: отправитель один — сервис.
    expect(DB::table('outbox_messages')->count())->toBe(0)
        ->and(Schema::hasTable('notifications'))->toBeFalse();
});

it('отказывает в production и не пишет данные', function (): void {
    $original = app()->environment();

    try {
        app()->detectEnvironment(static fn (): string => 'production');

        expect(static fn () => (new DemoSeeder)->run())
            ->toThrow(RuntimeException::class);
    } finally {
        app()->detectEnvironment(static fn (): string => $original);
    }

    expect(User::query()->count())->toBe(0);
});

it('печатает учётные записи и ключевые данные командой demo:accounts', function (): void {
    seedDemo();

    $this->artisan('demo:accounts')
        ->expectsOutputToContain('sender@courier.test')
        ->expectsOutputToContain('CRG-2026-900001')
        ->assertSuccessful();
});

it('отказывает demo:accounts в production', function (): void {
    $original = app()->environment();

    try {
        app()->detectEnvironment(static fn (): string => 'production');

        $this->artisan('demo:accounts')->assertFailed();
    } finally {
        app()->detectEnvironment(static fn (): string => $original);
    }
});
