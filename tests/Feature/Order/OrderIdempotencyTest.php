<?php

declare(strict_types=1);

use App\Models\Order\IdempotencyKey;
use App\Models\User;
use App\Services\Order\RequestFingerprint;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    estimateServiceZone();
    Sanctum::actingAs(User::factory()->sender()->create());
});

it('возвращает тот же ответ на повтор с тем же телом и не создаёт второй заказ', function (): void {
    $payload = orderPayload();

    $first = $this->postJson('/api/v1/orders', $payload, idempotencyHeader())->assertCreated();
    $second = $this->postJson('/api/v1/orders', $payload, idempotencyHeader())->assertCreated();

    // jsonb не сохраняет порядок ключей, поэтому сравниваем значения.
    expect($second->json())->toEqual($first->json());

    $this->assertDatabaseCount('delivery_orders', 1);
    $this->assertDatabaseCount('order_status_history', 1);
    $this->assertDatabaseCount('outbox_messages', 1);
});

it('не зависит от порядка полей в теле', function (): void {
    $payload = orderPayload();
    $reordered = array_reverse($payload, true);

    $this->postJson('/api/v1/orders', $payload, idempotencyHeader())->assertCreated();
    $this->postJson('/api/v1/orders', $reordered, idempotencyHeader())->assertCreated();

    $this->assertDatabaseCount('delivery_orders', 1);
});

it('отклоняет повтор с тем же ключом и другим телом', function (): void {
    $this->postJson('/api/v1/orders', orderPayload(), idempotencyHeader())->assertCreated();

    $response = $this->postJson('/api/v1/orders', orderPayload([
        'parcel' => [
            'weight_grams' => 2500,
            'dimensions' => ['length' => 30, 'width' => 20, 'height' => 10],
        ],
    ]), idempotencyHeader());

    $response->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'IDEMPOTENCY_KEY_CONFLICT');

    $this->assertDatabaseCount('delivery_orders', 1);
});

it('дает разным пользователям свои заказы на один и тот же ключ', function (): void {
    $this->postJson('/api/v1/orders', orderPayload(), idempotencyHeader())->assertCreated();

    Sanctum::actingAs(User::factory()->sender()->create());

    $this->postJson('/api/v1/orders', orderPayload(), idempotencyHeader())->assertCreated();

    $this->assertDatabaseCount('delivery_orders', 2);
});

it('отклоняет создание без ключа идемпотентности', function (): void {
    $response = $this->postJson('/api/v1/orders', orderPayload());

    $response->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('details.0.field', 'Idempotency-Key');

    $this->assertDatabaseCount('delivery_orders', 0);
});

it('отклоняет слишком короткий ключ идемпотентности', function (string $key): void {
    $response = $this->postJson('/api/v1/orders', orderPayload(), idempotencyHeader($key));

    $response->assertStatus(422)
        ->assertJsonPath('code', 'VALIDATION_FAILED')
        ->assertJsonPath('details.0.field', 'Idempotency-Key');

    $this->assertDatabaseCount('delivery_orders', 0);
})->with([
    'пустой' => [''],
    'короче шестнадцати' => ['short-key-1'],
    'длиннее шестидесяти четырёх' => [str_repeat('k', 65)],
]);

it('сообщает о выполняющемся запросе с тем же ключом', function (): void {
    $payload = orderPayload();
    $fingerprint = (new RequestFingerprint)->of($payload);

    // Имитация параллельного запроса: ключ занят, ответа ещё нет (FR-ORD-110).
    IdempotencyKey::query()->create([
        'user_id' => User::query()->sole()->id,
        'key' => 'order-key-0123456789',
        'request_hash' => $fingerprint,
        'status' => IdempotencyKey::STATUS_IN_PROGRESS,
        'expires_at' => now()->addHour(),
    ]);

    $response = $this->postJson('/api/v1/orders', $payload, idempotencyHeader());

    $response->assertStatus(409)
        ->assertJsonPath('code', 'IDEMPOTENCY_KEY_IN_PROGRESS');

    $this->assertDatabaseCount('delivery_orders', 0);
});

it('освобождает ключ после неудачного запроса', function (): void {
    $this->postJson('/api/v1/orders', orderPayload([
        'pickup' => estimateAddress(59.938784, 30.314997),
    ]), idempotencyHeader())->assertStatus(422);

    $this->assertDatabaseCount('idempotency_keys', 0);

    // Повтор с исправленным телом проходит.
    $this->postJson('/api/v1/orders', orderPayload(), idempotencyHeader())->assertCreated();
});

it('удаляет истёкшие ключи командой', function (): void {
    $user = User::query()->sole();

    $fresh = IdempotencyKey::query()->create([
        'user_id' => $user->id,
        'key' => 'fresh-key-00000000000',
        'request_hash' => 'hash',
        'status' => IdempotencyKey::STATUS_COMPLETED,
        'expires_at' => now()->addHour(),
    ]);

    $expired = IdempotencyKey::query()->create([
        'user_id' => $user->id,
        'key' => 'expired-key-000000000',
        'request_hash' => 'hash',
        'status' => IdempotencyKey::STATUS_COMPLETED,
        'expires_at' => now()->subHour(),
    ]);

    $this->artisan('orders:purge-idempotency-keys')->assertSuccessful();

    $this->assertDatabaseMissing('idempotency_keys', ['id' => $expired->id]);
    $this->assertDatabaseHas('idempotency_keys', ['id' => $fresh->id]);
});

it('не возвращает сохранённый ответ по просроченному ключу', function (): void {
    $payload = orderPayload();

    $this->postJson('/api/v1/orders', $payload, idempotencyHeader())->assertCreated();

    IdempotencyKey::query()->update(['expires_at' => now()->subMinute()->toIso8601String()]);

    $this->postJson('/api/v1/orders', $payload, idempotencyHeader())->assertCreated();

    // Просроченный ключ не блокирует повтор: создаётся новый заказ.
    $this->assertDatabaseCount('delivery_orders', 2);
});
