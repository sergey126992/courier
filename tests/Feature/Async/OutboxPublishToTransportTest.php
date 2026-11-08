<?php

declare(strict_types=1);

use App\Services\Events\Contracts\EventBus;
use App\Services\Events\OutboxPublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\Outbox;
use Tests\Support\TransportEventBus;

// Публикация в транспорт — единственный путь событий: отправитель
// уведомлений один, локального слушателя в монолите нет (FR-NTF-403).

afterEach(function (): void {
    Carbon::setTestNow();
});

it('ставит published_at только после успешной передачи транспорту', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    $bus = new TransportEventBus;
    app()->instance(EventBus::class, $bus);

    $message = Outbox::create();

    $result = app(OutboxPublisher::class)->run();

    $message->refresh();

    expect($result->published)->toBe(1)
        ->and($result->failed)->toBe(0)
        ->and($message->published_at)->not->toBeNull()
        ->and($message->attempts)->toBe(0)
        ->and($bus->published)->toHaveCount(1)
        ->and($bus->published[0]->messageId)->toBe($message->message_id)
        // Сквозной идентификатор строки Outbox доезжает до конверта (FR-OPS-201).
        ->and($bus->published[0]->correlationId)->toBe($message->correlation_id);
});

it('не ставит published_at при сбое транспорта и назначает backoff', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');
    config(['events.publish_backoff_seconds' => [5, 15]]);

    $bus = new TransportEventBus;
    $bus->failing = true;
    app()->instance(EventBus::class, $bus);

    $message = Outbox::create();

    $result = app(OutboxPublisher::class)->run();

    $message->refresh();

    $availableAt = Carbon::parse((string) DB::table('outbox_messages')
        ->where('id', $message->id)
        ->value('available_at'));

    expect($result->published)->toBe(0)
        ->and($result->failed)->toBe(1)
        ->and($message->published_at)->toBeNull()
        ->and($message->attempts)->toBe(1)
        ->and($message->last_error)->toBe('OUTBOX_PUBLISH_FAILED')
        ->and($availableAt->utc()->equalTo(now()->addSeconds(5)->utc()))->toBeTrue()
        ->and($bus->published)->toHaveCount(0);
});

it('публикует событие повторно после восстановления транспорта', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');
    config(['events.publish_backoff_seconds' => [5]]);

    $bus = new TransportEventBus;
    $bus->failing = true;
    app()->instance(EventBus::class, $bus);

    $message = Outbox::create();

    app(OutboxPublisher::class)->run();

    expect($message->refresh()->published_at)->toBeNull();

    $bus->failing = false;
    Carbon::setTestNow(now()->addSeconds(5));

    $result = app(OutboxPublisher::class)->run();

    $message->refresh();

    expect($result->published)->toBe(1)
        ->and($bus->calls)->toBe(2)
        ->and($message->published_at)->not->toBeNull()
        ->and($message->attempts)->toBe(1);
});

it('не публикует повторно событие с заполненным published_at', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    $bus = new TransportEventBus;
    app()->instance(EventBus::class, $bus);

    $message = Outbox::create(['published_at' => now()]);

    $result = app(OutboxPublisher::class)->run();

    $message->refresh();

    expect($result->published)->toBe(0)
        ->and($bus->calls)->toBe(0)
        ->and($message->published_at)->not->toBeNull();
});
