<?php

declare(strict_types=1);

use App\Domain\Geography\Contracts\DistanceProvider;
use App\Domain\Geography\Exceptions\DistanceProviderUnavailable;
use App\Domain\Identity\Role;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OutboxMessage;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// Создание заказа требует зону по адресу забора.
beforeEach(function (): void {
    estimateServiceZone();
});

/**
 * Подтверждённый отправитель с токеном.
 */
function orderAsSender(): User
{
    $user = User::factory()->sender()->create();
    Sanctum::actingAs($user);

    return $user;
}

it('создаёт заказ с номером, кодом трекинга, историей и событием', function (): void {
    $sender = orderAsSender();

    $response = $this->postJson('/api/v1/orders', orderPayload(), idempotencyHeader());

    $response->assertCreated()
        ->assertJsonPath('data.status', 'created')
        ->assertJsonPath('data.transport', 'light')
        ->assertJsonPath('data.light_vehicle_kind', 'bicycle')
        ->assertJsonPath('data.distance_km', 1.8)
        ->assertJsonPath('data.price.amount', 19400)
        ->assertJsonPath('data.price.currency', 'RUB')
        ->assertJsonPath('data.pickup.house', '7')
        ->assertJsonPath('data.dropoff.house', '9');

    /** @var array<string, mixed> $body */
    $body = (array) $response->json('data');

    expect((string) $body['number'])->toMatch('/^CRG-\d{4}-\d{6}$/')
        ->and((string) $body['tracking_code'])->toMatch('/^trk_[a-z0-9]{8,32}$/')
        ->and((int) $body['eta_minutes'])->toBeGreaterThan(0)
        // Разбивка сохраняется целиком: надбавка за час пик есть, но равна нулю.
        ->and($body['breakdown'])->toHaveCount(3)
        ->and($body['breakdown'][2]['amount'])->toBe(0);

    $order = DeliveryOrder::query()->sole();

    expect($order->sender_id)->toBe($sender->id)
        ->and($order->price_amount)->toBe(19400)
        ->and($body['number'])->toBe($order->number)
        ->and($body['tracking_code'])->toBe($order->tracking_code)
        ->and((string) $response->headers->get('Location'))->toEndWith('/api/v1/orders/'.$order->id);

    $this->assertDatabaseHas('order_status_history', [
        'order_id' => $order->id,
        'from_status' => null,
        'to_status' => 'created',
        'changed_by' => $sender->id,
    ]);

    $this->assertDatabaseHas('outbox_messages', [
        'aggregate_type' => 'order',
        'aggregate_id' => $order->id,
        'event_type' => 'order.created',
    ]);

    $message = OutboxMessage::query()->sole();
    $payload = $message->payload;

    // В событии нет персональных данных: только идентификаторы, район, транспорт и сумма.
    expect($payload)->toHaveKeys(['order_id', 'sender_id', 'pickup_district', 'transport', 'price_amount', 'price_currency'])
        ->and($payload['price_amount'])->toBe(19400)
        ->and($payload)->not->toHaveKey('dropoff_address')
        ->and($payload)->not->toHaveKey('pickup_address');
});

it('фиксирует цену заказа и не пересчитывает её после изменения тарифов', function (): void {
    orderAsSender();

    $this->postJson('/api/v1/orders', orderPayload(), idempotencyHeader('order-key-aaaaaaaaaaaa'))->assertCreated();

    $before = DeliveryOrder::query()->sole();
    $beforeBreakdown = $before->price_breakdown;

    config(['pricing.transport.light.base_rate_minor' => 9900]);

    // Новый тариф действует только на новые заказы.
    $this->postJson('/api/v1/orders', orderPayload(), idempotencyHeader('order-key-bbbbbbbbbbbb'))->assertCreated();

    $reloaded = DeliveryOrder::query()->whereKey($before->id)->sole();

    /** @var list<int> $prices */
    $prices = array_map(
        static fn (mixed $amount): int => (int) $amount,
        DeliveryOrder::query()->orderBy('id')->pluck('price_amount')->all(),
    );

    expect($reloaded->price_amount)->toBe(19400)
        ->and($reloaded->price_breakdown)->toBe($beforeBreakdown)
        ->and($prices)->toBe([19400, 14400]);
});

it('отклоняет забор по адресу вне зон обслуживания', function (): void {
    orderAsSender();

    $response = $this->postJson('/api/v1/orders', orderPayload([
        'pickup' => estimateAddress(59.938784, 30.314997),
    ]), idempotencyHeader());

    $response->assertStatus(422)
        ->assertJsonPath('code', 'ADDRESS_OUTSIDE_SERVICE_AREA')
        ->assertJsonPath('details.0.field', 'pickup');

    $this->assertDatabaseCount('delivery_orders', 0);
});

it('отвечает 503, когда провайдер расстояний недоступен, и снимает ключ повтора', function (): void {
    orderAsSender();

    app()->instance(
        DistanceProvider::class,
        new FakeDistanceProvider(failure: DistanceProviderUnavailable::forProvider('внешний', 'таймаут')),
    );

    $response = $this->postJson('/api/v1/orders', orderPayload(), idempotencyHeader());

    $response->assertStatus(503)->assertJsonPath('code', 'DISTANCE_PROVIDER_UNAVAILABLE');

    $this->assertDatabaseCount('delivery_orders', 0);
    // Ключ освобождён: клиент может повторить запрос с тем же заголовком.
    $this->assertDatabaseCount('idempotency_keys', 0);
});

it('отклоняет момент заявки в прошлом', function (): void {
    orderAsSender();

    $response = $this->postJson('/api/v1/orders', orderPayload([
        'requested_at' => now()->subDay()->toIso8601String(),
    ]), idempotencyHeader());

    $response->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('details.0.field', 'requested_at');
});

it('допускает момент заявки в пределах расхождения часов', function (): void {
    orderAsSender();

    $this->postJson('/api/v1/orders', orderPayload([
        'requested_at' => now()->subMinutes(2)->toIso8601String(),
    ]), idempotencyHeader())->assertCreated();
});

it('закрывает создание заказа неподтверждённой почте', function (): void {
    Sanctum::actingAs(User::factory()->sender()->unverified()->create());

    $this->postJson('/api/v1/orders', orderPayload(), idempotencyHeader())
        ->assertStatus(403)
        ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
});

it('закрывает создание заказа ролям без права', function (Role $role): void {
    $user = match ($role) {
        Role::Courier => User::factory()->courier()->create(),
        Role::Dispatcher => User::factory()->dispatcher()->create(),
        default => User::factory()->admin()->create(),
    };

    Sanctum::actingAs($user);

    $this->postJson('/api/v1/orders', orderPayload(), idempotencyHeader())
        ->assertStatus(403)
        ->assertJsonPath('code', 'FORBIDDEN');

    $this->assertDatabaseCount('delivery_orders', 0);
})->with([
    'курьер' => [Role::Courier],
    'диспетчер' => [Role::Dispatcher],
    'администратор' => [Role::Admin],
]);

it('требует токен', function (): void {
    $this->postJson('/api/v1/orders', orderPayload(), idempotencyHeader())
        ->assertStatus(401)
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('отклоняет слишком тяжёлую посылку', function (): void {
    orderAsSender();

    $payload = orderPayload([
        'transport' => 'on_foot',
        'parcel' => [
            'weight_grams' => (int) config('pricing.transport.on_foot.max_weight_grams') + 1000,
            'dimensions' => ['length' => 30, 'width' => 20, 'height' => 10],
        ],
    ]);
    // Для пешего транспорта вид не передаётся вовсе.
    unset($payload['light_vehicle_kind']);

    $response = $this->postJson('/api/v1/orders', $payload, idempotencyHeader());

    $response->assertStatus(422)
        ->assertJsonPath('code', 'PARCEL_TOO_HEAVY_FOR_TRANSPORT')
        ->assertJsonPath('details.0.field', 'parcel.weight_grams');

    $this->assertDatabaseCount('delivery_orders', 0);
});

it('отклоняет маршрут вне радиуса транспорта', function (): void {
    orderAsSender();

    [$pickup, $dropoff] = estimateRoute((float) config('pricing.transport.light.max_radius_km') + 5);

    $response = $this->postJson('/api/v1/orders', orderPayload([
        'pickup' => $pickup,
        'dropoff' => $dropoff,
    ]), idempotencyHeader());

    $response->assertStatus(422)
        ->assertJsonPath('code', 'PARCEL_OUTSIDE_DELIVERY_RADIUS')
        ->assertJsonPath('details.0.field', 'distance_km');
});

it('отклоняет попытку передать зону в запросе', function (): void {
    orderAsSender();

    $response = $this->postJson('/api/v1/orders', orderPayload(['zone_id' => 1]), idempotencyHeader());

    $response->assertStatus(422)->assertJsonPath('code', 'VALIDATION_FAILED');

    expect((string) $response->json('details.0.field'))->toBe('zone_id');
});

it('ограничивает частоту создания заказов', function (): void {
    config(['rate_limits.orders_create.attempts' => 1]);

    orderAsSender();

    $this->postJson('/api/v1/orders', orderPayload(), idempotencyHeader('order-key-first000000000'))->assertCreated();

    $response = $this->postJson('/api/v1/orders', orderPayload(), idempotencyHeader('order-key-second00000000'));

    $response->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'RATE_LIMITED');
});
