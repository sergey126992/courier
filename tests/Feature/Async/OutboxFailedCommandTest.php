<?php

declare(strict_types=1);

use App\Models\Order\OutboxMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Outbox;

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * JSON-вывод команды outbox:failed.
 *
 * @return array<string, mixed>
 */
function outboxFailedPayload(): array
{
    /** @var array<string, mixed> $payload */
    $payload = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    return $payload;
}

it('показывает неопубликованные и необработанные события', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    $maxAttempts = (int) config('events.publish_max_attempts');
    $pending = Outbox::create();
    $unprocessed = Outbox::create([
        'attempts' => $maxAttempts,
        'last_error' => 'EVENT_DELIVERY_FAILED',
    ]);
    Outbox::create(['published_at' => Carbon::now()]);

    $exit = Artisan::call('outbox:failed', ['--json' => true]);

    /** @var list<array<string, mixed>> $events */
    $events = outboxFailedPayload()['events'];

    expect($exit)->toBe(0)
        ->and(count($events))->toBe(2)
        ->and($events[0]['id'])->toBe((int) $pending->getKey())
        ->and($events[0]['attempts'])->toBe(0)
        ->and($events[0]['unprocessed'])->toBeFalse()
        ->and($events[0]['message_id'])->toBe((string) $pending->fresh()?->message_id)
        ->and($events[0]['event_type'])->toBe('order.created')
        ->and($events[1]['id'])->toBe((int) $unprocessed->getKey())
        ->and($events[1]['attempts'])->toBe($maxAttempts)
        ->and($events[1]['unprocessed'])->toBeTrue()
        ->and($events[1]['last_error'])->toBe('EVENT_DELIVERY_FAILED');
});

it('ограничивает список флагом --limit', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    Outbox::create();
    Outbox::create(['aggregate_id' => 2]);
    Outbox::create(['aggregate_id' => 3]);

    Artisan::call('outbox:failed', ['--json' => true, '--limit' => 2]);

    /** @var list<mixed> $events */
    $events = outboxFailedPayload()['events'];

    expect(count($events))->toBe(2)
        ->and(OutboxMessage::query()->whereNull('published_at')->count())->toBe(3);
});

it('возвращает 0 и пустой список, когда неопубликованных событий нет', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');
    Outbox::create(['published_at' => Carbon::now()]);

    $exit = Artisan::call('outbox:failed', ['--json' => true]);

    expect($exit)->toBe(0)
        ->and(outboxFailedPayload()['events'])->toBe([]);
});

it('возвращает 2 при некорректном --limit', function (): void {
    $this->artisan('outbox:failed', ['--limit' => 'abc'])->assertExitCode(2);
});

it('не выводит персональные данные', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');
    Outbox::create(['attempts' => 5, 'last_error' => 'EVENT_DELIVERY_FAILED']);

    $exit = Artisan::call('outbox:failed');
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->not->toContain('Северный')
        ->and($output)->not->toContain('@')
        ->and($output)->not->toContain('19900');
});
