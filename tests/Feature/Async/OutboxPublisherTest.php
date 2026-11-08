<?php

declare(strict_types=1);

use App\Domain\Events\EventType;
use App\Models\Order\OutboxMessage;
use App\Services\Events\LocalEventBus;
use App\Services\Events\OutboxPublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\Outbox;
use Tests\Support\RecordingEventListener;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('публикует пачку и передаёт событие слушателю', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    $message = Outbox::create();
    $message->refresh();

    $listener = new RecordingEventListener;
    app(LocalEventBus::class)->register(EventType::OrderCreated, $listener);

    $result = app(OutboxPublisher::class)->run();

    expect($result->published)->toBe(1)
        ->and($result->failed)->toBe(0)
        ->and($listener->handled)->toBe([$message->message_id]);

    $message->refresh();

    expect($message->published_at)->not->toBeNull()
        ->and($message->attempts)->toBe(0);
});

it('не берёт опубликованные и не готовые по времени строки', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    $ready = Outbox::create();
    $published = Outbox::create();
    $published->update(['published_at' => now()]);
    $later = Outbox::create(['available_at' => now()->addMinutes(5)]);

    $listener = new RecordingEventListener;
    app(LocalEventBus::class)->register(EventType::OrderCreated, $listener);

    $result = app(OutboxPublisher::class)->run();

    expect($result->published)->toBe(1)
        ->and($listener->handled)->toHaveCount(1);

    $ready->refresh();
    $later->refresh();
    $published->refresh();

    expect($ready->published_at)->not->toBeNull()
        ->and($published->published_at)->not->toBeNull()
        ->and($later->published_at)->toBeNull();
});

it('ограничивает пачку размером из конфигурации', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');
    config(['events.publish_batch_size' => 2]);

    Outbox::create();
    Outbox::create();
    Outbox::create();

    $result = app(OutboxPublisher::class)->run();

    expect($result->published)->toBe(2)
        ->and(OutboxMessage::query()->whereNull('published_at')->count())->toBe(1);
});

it('не работает, пока блокировку держит другой воркер', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');
    Outbox::create();

    $lock = Cache::lock(OutboxPublisher::LOCK_KEY, 60);
    expect($lock->get())->toBeTrue();

    try {
        $first = app(OutboxPublisher::class)->run();

        expect($first->lockBusy)->toBeTrue()
            ->and($first->published)->toBe(0);
    } finally {
        $lock->release();
    }

    $second = app(OutboxPublisher::class)->run();

    expect($second->lockBusy)->toBeFalse()
        ->and($second->published)->toBe(1)
        ->and(OutboxMessage::query()->whereNotNull('published_at')->count())->toBe(1);
});

it('фиксирует сбой слушателя и продолжает пачку', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');
    config(['events.publish_backoff_seconds' => [5, 15, 60]]);

    $failing = Outbox::create();
    $succeeding = Outbox::cancelled();

    app(LocalEventBus::class)->register(EventType::OrderCreated, new RecordingEventListener(failing: true));
    app(LocalEventBus::class)->register(EventType::OrderCancelled, new RecordingEventListener);

    $result = app(OutboxPublisher::class)->run();

    expect($result->published)->toBe(1)
        ->and($result->failed)->toBe(1);

    $failing->refresh();
    $succeeding->refresh();

    $availableAt = Carbon::parse((string) DB::table('outbox_messages')
        ->where('id', $failing->id)
        ->value('available_at'));

    expect($failing->attempts)->toBe(1)
        ->and($failing->last_error)->toBe('OUTBOX_PUBLISH_FAILED')
        ->and($failing->published_at)->toBeNull()
        ->and($availableAt->utc()->equalTo(now()->addSeconds(5)->utc()))->toBeTrue()
        ->and($succeeding->published_at)->not->toBeNull();
});

it('не выбирает строку после исчерпания попыток', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');
    config([
        'events.publish_max_attempts' => 2,
        'events.publish_backoff_seconds' => [5, 15],
    ]);

    $message = Outbox::create();
    app(LocalEventBus::class)->register(EventType::OrderCreated, new RecordingEventListener(failing: true));

    app(OutboxPublisher::class)->run();

    Carbon::setTestNow(now()->addSeconds(10));
    app(OutboxPublisher::class)->run();

    $message->refresh();

    expect($message->attempts)->toBe(2)
        ->and($message->published_at)->toBeNull()
        ->and($message->last_error)->toBe('OUTBOX_PUBLISH_FAILED');

    Carbon::setTestNow(now()->addHour());
    $result = app(OutboxPublisher::class)->run();

    expect($result->published)->toBe(0)
        ->and($result->failed)->toBe(0)
        ->and($message->refresh()->attempts)->toBe(2);
});

it('публикует событие повторно без второго эффекта у потребителя', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');
    config(['events.publish_backoff_seconds' => [5]]);

    $message = Outbox::create();
    $message->refresh();

    $listener = new RecordingEventListener(failing: true);
    app(LocalEventBus::class)->register(EventType::OrderCreated, $listener);

    app(OutboxPublisher::class)->run();

    expect($message->refresh()->published_at)->toBeNull();

    $listener->failing = false;
    Carbon::setTestNow(now()->addSeconds(5));

    $result = app(OutboxPublisher::class)->run();

    expect($result->published)->toBe(1)
        ->and($listener->calls)->toBe(2)
        ->and($listener->handled)->toBe([$message->message_id])
        ->and($message->refresh()->published_at)->not->toBeNull();
});

it('помечает опубликованным событие неизвестного типа и пишет лог', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');
    $logger = Log::spy();

    $message = Outbox::create(['event_type' => 'order.unknown']);

    $result = app(OutboxPublisher::class)->run();

    $message->refresh();

    expect($result->published)->toBe(1)
        ->and($result->failed)->toBe(0)
        ->and($message->published_at)->not->toBeNull()
        ->and($message->attempts)->toBe(0);

    $logger->shouldHaveReceived('warning')->once()->withArgs(function (string $line, array $context): bool {
        return ($context['code'] ?? null) === 'EVENT_TYPE_UNKNOWN'
            && isset($context['message_id'], $context['event_type']);
    });
});

it('не видит строку незавершённой транзакции операции', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    config(['database.connections.outbox_probe' => config('database.connections.pgsql')]);

    $probe = DB::connection('outbox_probe');
    $probe->beginTransaction();

    try {
        $probe->table('outbox_messages')->insert([
            'message_id' => (string) Str::uuid(),
            'aggregate_type' => 'order',
            'aggregate_id' => 1,
            'event_type' => 'order.created',
            'payload' => json_encode(['order_id' => 1], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'available_at' => now()->utc()->format('Y-m-d H:i:sP'),
            'created_at' => now()->utc()->format('Y-m-d H:i:sP'),
        ]);

        $result = app(OutboxPublisher::class)->run();

        expect($result->published)->toBe(0)
            ->and($result->failed)->toBe(0)
            ->and($probe->table('outbox_messages')->count())->toBe(1);
    } finally {
        $probe->rollBack();
        DB::purge('outbox_probe');
    }
});
