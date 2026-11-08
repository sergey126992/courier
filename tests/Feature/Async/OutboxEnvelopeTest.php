<?php

declare(strict_types=1);

use App\Models\Order\DeliveryOrder;
use App\Models\Order\OutboxMessage;
use App\Models\User;
use App\Services\Events\OutboxEventEnvelopeFactory;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('несёт correlation_id запроса и общий состав payload события заказа', function (): void {
    // Адреса заказа требуют зоны обслуживания по адресу забора.
    estimateServiceZone();

    $sender = User::factory()->sender()->create();
    Sanctum::actingAs($sender);

    $requestId = 'req-0123456789abcdef';

    $this->postJson('/api/v1/orders', orderPayload(), [
        ...idempotencyHeader(),
        'X-Request-Id' => $requestId,
    ])->assertCreated();

    $message = OutboxMessage::query()->where('event_type', 'order.created')->sole();
    $order = DeliveryOrder::query()->findOrFail((int) $message->aggregate_id);

    // correlation_id события совпадает с request_id запроса (FR-OPS-201).
    expect($message->correlation_id)->toBe($requestId)
        ->and($message->payload)->toMatchArray([
            // event_id равен message_id строки Outbox (FR-NTF-223).
            'event_id' => $message->message_id,
            'order_id' => $order->id,
            'tracking_code' => $order->tracking_code,
            'sender_id' => $sender->id,
            'status' => 'created',
            'locale' => 'ru',
        ])
        ->and($message->payload['occurred_at'])->toBeString();

    $envelope = app(OutboxEventEnvelopeFactory::class)->fromMessage($message);

    expect($envelope->correlationId)->toBe($requestId)
        ->and($envelope->toArray()['correlation_id'])->toBe($requestId);
});

it('генерирует correlation_id вне HTTP-запроса', function (): void {
    // Консольный и очередной контекст: атрибута request_id у запроса нет.
    app()->instance('request', Request::create('/console'));

    $message = OutboxMessage::create([
        'aggregate_type' => 'order',
        'aggregate_id' => 1,
        'event_type' => 'order.created',
        'payload' => [
            'event_id' => '0c1e5f6a-1b2c-4d3e-8f90-1a2b3c4d5e6f',
            'order_id' => 1,
            'tracking_code' => 'TRK-2026-000001',
            'sender_id' => 1,
            'status' => 'created',
            'occurred_at' => '2026-05-01T09:00:00+00:00',
            'locale' => 'ru',
            'pickup_district' => 'Северный',
            'transport' => 'light',
            'price_amount' => 19900,
            'price_currency' => 'RUB',
        ],
    ]);

    expect(Str::isUuid((string) $message->correlation_id))->toBeTrue();
});
