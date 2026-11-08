<?php

declare(strict_types=1);

use App\Models\Order\DeliveryOrder;
use App\Models\Order\OutboxMessage;
use App\Models\User;
use App\Services\Events\Contracts\EventBus;
use App\Services\Events\OutboxPublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Outbox;
use Tests\Support\TransportEventBus;

/*
|--------------------------------------------------------------------------
| Изоляция сбоя сервиса уведомлений (ЧТЗ 5.5, 9.5; FR-NTF-217, FR-NTF-294)
|--------------------------------------------------------------------------
|
| Сервис остановлен — заказы создаются и меняют статус, HTTP-путь монолита
| не обращается к сервису, Outbox растёт; накопленная пачка уходит после
| восстановления транспорта ровно один раз. Сети нет: HTTP и транспорт
| подменены (ЧТЗ 9.7).
|
*/

beforeEach(function (): void {
    estimateServiceZone();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Настройки недоступного сервиса оповещений.
 */
function isolationServiceMode(): void
{
    config([
        'notifications.service.url' => 'http://notifications.test',
        'notifications.service.timeout_seconds' => 1,
        'notifications.service.retry_times' => 0,
        'notifications.service.retry_sleep_milliseconds' => 0,
    ]);
}

it('создаёт заказ при остановленном сервисе, не обращаясь к нему', function (): void {
    isolationServiceMode();

    // HTTP-путь заказа не должен ждать сервис: любой запрос к нему — дефект.
    Http::preventStrayRequests();
    Http::fake();

    $sender = User::factory()->sender()->create();
    Sanctum::actingAs($sender);

    $this->postJson('/api/v1/orders', orderPayload(), idempotencyHeader())
        ->assertCreated()
        ->assertJsonPath('data.status', 'created');

    $message = OutboxMessage::query()->sole();

    // Монолит не создаёт локальных уведомлений: отправитель один — сервис
    // (FR-NTF-403, FR-NTF-406).
    expect($message->event_type)->toBe('order.created')
        ->and($message->published_at)->toBeNull();

    Http::assertNothingSent();
});

it('меняет статус заказа при остановленном сервисе и растит Outbox', function (): void {
    isolationServiceMode();

    Http::preventStrayRequests();
    Http::fake();

    $sender = User::factory()->sender()->create();
    Sanctum::actingAs($sender);
    $order = DeliveryOrder::factory()->create(['sender_id' => $sender->id]);

    $this->postJson('/api/v1/orders/'.$order->id.'/cancel')
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled');

    $this->assertDatabaseHas('delivery_orders', ['id' => $order->id, 'status' => 'cancelled']);
    $this->assertDatabaseHas('outbox_messages', [
        'aggregate_id' => $order->id,
        'event_type' => 'order.cancelled',
        'published_at' => null,
    ]);

    Http::assertNothingSent();
});

it('публикует накопленную пачку после восстановления транспорта без дублей', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');
    config([
        'events.publish_backoff_seconds' => [5],
    ]);

    $bus = new TransportEventBus;
    $bus->failing = true;
    app()->instance(EventBus::class, $bus);

    // Три события накопились, пока сервис был недоступен.
    Outbox::create(['aggregate_id' => 1]);
    Outbox::create(['aggregate_id' => 2]);
    Outbox::create(['aggregate_id' => 3]);

    $failed = app(OutboxPublisher::class)->run();

    expect($failed->published)->toBe(0)
        ->and($failed->failed)->toBe(3)
        ->and(OutboxMessage::query()->whereNull('published_at')->count())->toBe(3)
        ->and($bus->published)->toBe([]);

    // Транспорт восстановлен: пачка уходит целиком и только при следующем проходе.
    $bus->failing = false;
    Carbon::setTestNow(now()->addSeconds(5));

    $first = app(OutboxPublisher::class)->run();
    $second = app(OutboxPublisher::class)->run();

    expect($first->published)->toBe(3)
        ->and($second->published)->toBe(0)
        ->and($bus->published)->toHaveCount(3)
        ->and(OutboxMessage::query()->whereNotNull('published_at')->count())->toBe(3);
});

it('показывает рост очереди в outbox:status при остановленном сервисе', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');
    $bus = new TransportEventBus;
    $bus->failing = true;
    app()->instance(EventBus::class, $bus);

    Outbox::create(['aggregate_id' => 1]);
    Outbox::create(['aggregate_id' => 2]);

    app(OutboxPublisher::class)->run();

    Carbon::setTestNow('2026-05-01 12:01:30');

    $exit = Artisan::call('outbox:status', ['--json' => true]);
    /** @var array<string, mixed> $payload */
    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(1)
        ->and($payload['status'])->toBe('degraded')
        ->and($payload['pending_count'])->toBe(2)
        ->and($payload['oldest_pending_age_seconds'])->toBe(90);
});
