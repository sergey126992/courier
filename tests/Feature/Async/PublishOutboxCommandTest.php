<?php

declare(strict_types=1);

use App\Domain\Events\EventType;
use App\Models\Order\OutboxMessage;
use App\Services\Events\LocalEventBus;
use App\Services\Events\OutboxPublisher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Tests\Support\Outbox;
use Tests\Support\RecordingEventListener;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('завершается успешно на пустой очереди', function (): void {
    $this->artisan('outbox:publish')->assertExitCode(0);
});

it('публикует не больше событий, чем задал --limit', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    Outbox::create();
    Outbox::create();

    $this->artisan('outbox:publish', ['--limit' => 1])->assertExitCode(0);

    expect(OutboxMessage::query()->whereNotNull('published_at')->count())->toBe(1)
        ->and(OutboxMessage::query()->whereNull('published_at')->count())->toBe(1);
});

it('возвращает 1, если слушатель не принял событие', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    Outbox::create();
    app(LocalEventBus::class)->register(EventType::OrderCreated, new RecordingEventListener(failing: true));

    $this->artisan('outbox:publish')->assertExitCode(1);
});

it('возвращает 2 при ошибке конфигурации', function (): void {
    config(['events.publish_batch_size' => 0]);

    $this->artisan('outbox:publish')->assertExitCode(2);
});

it('возвращает 2 при некорректном --limit', function (): void {
    $this->artisan('outbox:publish', ['--limit' => 'abc'])->assertExitCode(2);
});

it('возвращает 0, если блокировку держит другой воркер', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    Outbox::create();

    $lock = Cache::lock(OutboxPublisher::LOCK_KEY, 60);
    expect($lock->get())->toBeTrue();

    try {
        $this->artisan('outbox:publish')->assertExitCode(0);
    } finally {
        $lock->release();
    }

    expect(OutboxMessage::query()->whereNotNull('published_at')->count())->toBe(0);
});

it('выводит счётчики в JSON', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    Outbox::create();

    $exit = Artisan::call('outbox:publish', ['--json' => true]);

    /** @var array<string, mixed> $payload */
    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($payload)->toMatchArray([
            'published' => 1,
            'failed' => 0,
            'lock_busy' => false,
        ]);
});
