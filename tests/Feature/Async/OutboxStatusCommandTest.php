<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Outbox;

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * JSON-вывод команды outbox:status.
 *
 * @return array<string, mixed>
 */
function outboxStatusPayload(): array
{
    /** @var array<string, mixed> $payload */
    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    return $payload;
}

it('на пустом Outbox возвращает 0, ok и null в oldest_pending_at', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    $exit = Artisan::call('outbox:status', ['--json' => true]);
    $payload = outboxStatusPayload();

    expect($exit)->toBe(0)
        ->and($payload['status'])->toBe('ok')
        ->and($payload['pending_count'])->toBe(0)
        ->and($payload['failed_count'])->toBe(0)
        ->and($payload['oldest_pending_at'])->toBeNull()
        ->and($payload['oldest_pending_age_seconds'])->toBeNull()
        ->and($payload['publish_lag_seconds'])->toBe(0)
        ->and($payload['published_last_hour'])->toBe(0)
        ->and($payload['thresholds'])->toBe([
            'max_lag_seconds' => (int) config('events.max_publish_lag_seconds'),
            'max_failed' => (int) config('events.max_unprocessed_count'),
        ])
        ->and((string) $payload['generated_at'])
        ->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/');
});

it('возвращает 1 и degraded, если есть необработанные события', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    $maxAttempts = (int) config('events.publish_max_attempts');
    Outbox::create(['attempts' => $maxAttempts, 'last_error' => 'EVENT_DELIVERY_FAILED']);

    $exit = Artisan::call('outbox:status', ['--json' => true]);
    $payload = outboxStatusPayload();

    expect($exit)->toBe(1)
        ->and($payload['status'])->toBe('degraded')
        ->and($payload['failed_count'])->toBe(1)
        ->and($payload['pending_count'])->toBe(1)
        ->and(Carbon::parse((string) $payload['oldest_pending_at'])->getTimestamp())
        ->toBe(Carbon::now()->getTimestamp());
});

it('возвращает 1 и degraded, если возраст неопубликованного превысил порог лага', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');
    Outbox::create();

    Carbon::setTestNow('2026-05-01 12:01:30');

    $exit = Artisan::call('outbox:status', ['--json' => true, '--max-lag' => 60]);
    $payload = outboxStatusPayload();

    expect($exit)->toBe(1)
        ->and($payload['status'])->toBe('degraded')
        ->and($payload['publish_lag_seconds'])->toBe(90)
        ->and($payload['oldest_pending_age_seconds'])->toBe(90)
        ->and($payload['thresholds'])->toBe(['max_lag_seconds' => 60, 'max_failed' => 0]);
});

it('возвращает 0, если флаг --max-lag поднимает порог выше лага', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');
    Outbox::create();

    Carbon::setTestNow('2026-05-01 12:01:30');

    $exit = Artisan::call('outbox:status', ['--json' => true, '--max-lag' => 120]);
    $payload = outboxStatusPayload();

    expect($exit)->toBe(0)
        ->and($payload['status'])->toBe('ok')
        ->and($payload['publish_lag_seconds'])->toBe(90);
});

it('считает публикации только за последний час', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    Outbox::create(['published_at' => Carbon::now()->subMinutes(10)]);
    Outbox::create(['published_at' => Carbon::now()->subHours(2), 'attempts' => 5]);

    $exit = Artisan::call('outbox:status', ['--json' => true]);
    $payload = outboxStatusPayload();

    expect($exit)->toBe(0)
        ->and($payload['published_last_hour'])->toBe(1)
        ->and($payload['pending_count'])->toBe(0);
});

it('возвращает 2 при некорректном --max-lag', function (): void {
    $this->artisan('outbox:status', ['--max-lag' => 'abc'])->assertExitCode(2);
});

it('не выводит персональные данные в JSON', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');
    Outbox::create(['attempts' => 5, 'last_error' => 'EVENT_DELIVERY_FAILED']);

    Artisan::call('outbox:status', ['--json' => true]);
    $output = Artisan::output();

    expect($output)->not->toContain('Северный')
        ->and($output)->not->toContain('@')
        ->and($output)->not->toContain('19900');
});
