<?php

declare(strict_types=1);

use App\Domain\Identity\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * Сумма строк разбивки ответа расчёта.
 *
 * @param array<int, array<string, mixed>> $lines
 */
function estimateBreakdownSum(array $lines): int
{
    return array_sum(array_map(static fn (array $line): int => (int) $line['amount'], $lines));
}

/**
 * Коды строк разбивки ответа расчёта.
 *
 * @param array<int, array<string, mixed>> $lines
 * @return list<string>
 */
function estimateBreakdownCodes(array $lines): array
{
    return array_values(array_map(static fn (array $line): string => (string) $line['code'], $lines));
}

/**
 * Действующий отправитель с подтверждённой почтой.
 */
function estimateAsSender(): User
{
    $user = User::factory()->sender()->create();
    Sanctum::actingAs($user);

    return $user;
}

// Расчёт требует зону по адресу забора: без неё сервер не может определить зону.
beforeEach(function (): void {
    estimateServiceZone();
});

it('возвращает цену, прогноз и разбивку для лёгкого транспорта', function (): void {
    estimateAsSender();

    $base = (int) config('pricing.transport.light.base_rate_minor');
    $perKm = (int) config('pricing.transport.light.rate_per_km_minor');

    // Маршрут заданной длины: расстояние считает сервер по координатам.
    $response = $this->postJson('/api/v1/estimates', estimatePayloadForRoute(4.2, [
        'transport' => 'light',
        'light_vehicle_kind' => 'e_bike',
        'parcel' => [
            'weight_grams' => 5000,
            'dimensions' => ['length' => 30, 'width' => 20, 'height' => 10],
        ],
    ]));

    $response->assertOk()
        ->assertJsonStructure([
            'transport',
            'light_vehicle_kind',
            'distance_km',
            'eta_minutes',
            'price' => ['amount', 'currency'],
            'breakdown' => [['code', 'label', 'amount']],
        ]);

    expect($response->json('transport'))->toBe('light')
        ->and($response->json('light_vehicle_kind'))->toBe('e_bike')
        ->and($response->json('distance_km'))->toBe(4.2)
        ->and($response->json('eta_minutes'))->toBeGreaterThan(0)
        ->and($response->json('price.currency'))->toBe((string) config('pricing.currency'));

    /** @var array<int, array<string, mixed>> $lines */
    $lines = (array) $response->json('breakdown');

    expect(estimateBreakdownCodes($lines))->toBe(['base', 'distance', 'surge'])
        ->and(estimateBreakdownSum($lines))->toBe((int) $response->json('price.amount'))
        // Цена по конфигурации: базовая ставка + ставка за километр × расстояние.
        ->and((int) $response->json('price.amount'))->toBe($base + (int) round($perKm * 4.2))
        ->and((int) $lines[2]['amount'])->toBe(0)
        ->and((string) $lines[1]['label'])->toBe('4.2 км');
});

it('принимает расстояние с двумя знаками после запятой', function (): void {
    estimateAsSender();

    $response = $this->postJson('/api/v1/estimates', estimatePayloadForRoute(4.25, [
        'transport' => 'light',
        'light_vehicle_kind' => 'bicycle',
    ]))->assertOk();

    /** @var array<int, array<string, mixed>> $lines */
    $lines = (array) $response->json('breakdown');

    expect((float) $response->json('distance_km'))->toBe(4.25)
        ->and((string) $lines[1]['label'])->toBe('4.25 км');
});

it('не пишет в базу данных', function (): void {
    estimateAsSender();

    $tables = ['users', 'sender_profiles', 'courier_profiles', 'personal_access_tokens', 'role_changes', 'service_zones'];
    $before = [];

    foreach ($tables as $table) {
        $before[$table] = DB::table($table)->count();
    }

    $this->postJson('/api/v1/estimates', estimatePayload())->assertOk();

    foreach ($tables as $table) {
        expect(DB::table($table)->count())->toBe($before[$table]);
    }
});

it('даёт идентичный ответ при повторе с теми же данными и временем', function (): void {
    estimateAsSender();

    $payload = estimatePayloadForRoute(12.5, [
        'transport' => 'car',
        'is_paid_parking' => true,
    ]);

    $first = $this->postJson('/api/v1/estimates', $payload)->assertOk();
    $second = $this->postJson('/api/v1/estimates', $payload)->assertOk();

    expect($second->json())->toBe($first->json());
});

it('даёт разные результаты для трёх транспортов на одном маршруте', function (): void {
    estimateAsSender();

    $onFoot = $this->postJson('/api/v1/estimates', estimatePayloadForRoute(1.8, ['transport' => 'on_foot']))->assertOk();
    $light = $this->postJson('/api/v1/estimates', estimatePayloadForRoute(1.8, ['transport' => 'light', 'light_vehicle_kind' => 'bicycle']))->assertOk();
    $car = $this->postJson('/api/v1/estimates', estimatePayloadForRoute(1.8, ['transport' => 'car']))->assertOk();

    $etas = [(int) $onFoot->json('eta_minutes'), (int) $light->json('eta_minutes'), (int) $car->json('eta_minutes')];
    $prices = [(int) $onFoot->json('price.amount'), (int) $light->json('price.amount'), (int) $car->json('price.amount')];

    expect(array_unique($etas))->toHaveCount(3)
        ->and(array_unique($prices))->toHaveCount(3)
        // Пеший — самый медленный, автомобиль — самый быстрый.
        ->and($etas[0])->toBeGreaterThan($etas[1])
        ->and($etas[1])->toBeGreaterThan($etas[2]);
});

it('требует токен', function (): void {
    $this->postJson('/api/v1/estimates', estimatePayload())
        ->assertStatus(401)
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('закрывает расчёт неподтверждённой почте', function (): void {
    Sanctum::actingAs(User::factory()->sender()->unverified()->create());

    $this->postJson('/api/v1/estimates', estimatePayload())
        ->assertStatus(403)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
});

it('закрывает расчёт ролям без права', function (Role $role): void {
    $user = match ($role) {
        Role::Courier => User::factory()->courier()->create(),
        Role::Dispatcher => User::factory()->dispatcher()->create(),
        default => User::factory()->admin()->create(),
    };

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/estimates', estimatePayload())
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');
})->with([
    'курьер' => [Role::Courier],
    'диспетчер' => [Role::Dispatcher],
    'администратор' => [Role::Admin],
]);

it('отклоняет слишком тяжёлую посылку с пределом в деталях', function (): void {
    estimateAsSender();

    $response = $this->postJson('/api/v1/estimates', estimatePayload([
        'transport' => 'on_foot',
        'parcel' => [
            'weight_grams' => (int) config('pricing.transport.on_foot.max_weight_grams') + 2400,
            'dimensions' => ['length' => 30, 'width' => 20, 'height' => 10],
        ],
    ]));

    $response->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'PARCEL_TOO_HEAVY_FOR_TRANSPORT')
        ->assertJsonPath('details.0.field', 'parcel.weight_grams');

    $message = (string) $response->json('details.0.message');

    expect($message)->toContain((string) config('pricing.transport.on_foot.max_weight_grams'))
        ->and($message)->toContain('Пешком');
});

it('отклоняет маршрут вне радиуса обслуживания', function (): void {
    estimateAsSender();

    $response = $this->postJson('/api/v1/estimates', estimatePayloadForRoute(
        (float) config('pricing.transport.car.max_radius_km') + 5,
        ['transport' => 'car'],
    ));

    $response->assertStatus(422)
        ->assertJsonPath('code', 'PARCEL_OUTSIDE_DELIVERY_RADIUS')
        ->assertJsonPath('details.0.field', 'distance_km');

    $message = (string) $response->json('details.0.message');

    expect($message)->toContain('20')
        ->and($message)->toContain('Автомобиль');
});

it('отклоняет несовместимый вид лёгкого транспорта', function (array $payload, string $field): void {
    estimateAsSender();

    $response = $this->postJson('/api/v1/estimates', $payload);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    $fields = array_map(
        static fn (array $item): string => (string) $item['field'],
        (array) $response->json('details'),
    );

    expect($fields)->toContain($field);
})->with([
    'лёгкий без вида' => [estimatePayload(['transport' => 'light']), 'light_vehicle_kind'],
    'вид при пешем' => [estimatePayload(['transport' => 'on_foot', 'light_vehicle_kind' => 'bicycle']), 'light_vehicle_kind'],
]);

it('даёт одно сообщение на старую плоскую форму посылки', function (): void {
    estimateAsSender();

    $payload = array_merge(
        array_diff_key(estimatePayload(), ['parcel' => true]),
        ['weight_grams' => 1500, 'dimensions' => ['length' => 30, 'width' => 20, 'height' => 10]],
    );

    $response = $this->postJson('/api/v1/estimates', $payload);

    $response->assertStatus(422)
        ->assertJsonPath('details.0.field', 'parcel')
        // Одно сообщение: вложенные поля не добавляют лавину ошибок.
        ->assertJsonCount(1, 'details');
});

it('отклоняет некорректные значения полей', function (array $payload, string $field): void {
    estimateAsSender();

    $response = $this->postJson('/api/v1/estimates', $payload);

    $response->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED');

    $fields = array_map(
        static fn (array $item): string => (string) $item['field'],
        (array) $response->json('details'),
    );

    expect($fields)->toContain($field);
})->with([
    'нулевой вес' => [estimatePayload([
        'parcel' => ['weight_grams' => 0, 'dimensions' => ['length' => 30, 'width' => 20, 'height' => 10]],
    ]), 'parcel.weight_grams'],
    // Старая плоская форма посылки больше не принимается.
    'плоская посылка' => [array_merge(
        array_diff_key(estimatePayload(), ['parcel' => true]),
        ['weight_grams' => 1500, 'dimensions' => ['length' => 30, 'width' => 20, 'height' => 10]],
    ), 'parcel'],
    // Расстояние от клиента больше не принимается: поле prohibited.
    'расстояние в запросе' => [estimatePayload(['distance_km' => 1.8]), 'distance_km'],
    'широта вне диапазона' => [estimatePayload(['pickup' => estimateAddress(91.0, 37.6173)]), 'pickup.latitude'],
    'долгота вне диапазона' => [estimatePayload(['dropoff' => estimateAddress(55.75, 181.0)]), 'dropoff.longitude'],
    'пустой адрес забора' => [estimatePayload(['pickup' => []]), 'pickup'],
    'неизвестный транспорт' => [estimatePayload(['transport' => 'rocket']), 'transport'],
    'отрицательная длина' => [estimatePayload([
        'parcel' => ['weight_grams' => 1500, 'dimensions' => ['length' => 0, 'width' => 20, 'height' => 10]],
    ]), 'parcel.dimensions.length'],
    'неизвестный вид лёгкого' => [estimatePayload(['transport' => 'light', 'light_vehicle_kind' => 'horse']), 'light_vehicle_kind'],
]);
