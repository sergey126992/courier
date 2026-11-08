<?php

declare(strict_types=1);

use App\Domain\Events\EventEnvelope;
use App\Domain\Events\EventPayload;
use App\Domain\Events\EventType;
use App\Models\Events\ProcessedMessage;
use App\Services\Events\Inbox;
use App\Services\Events\InboxResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Конверт события с payload, удовлетворяющим реестру типов.
 */
function inboxEnvelope(string $messageId = '5b9c0f7e-3a52-4d61-9c3f-8f0e2d4b6a71'): EventEnvelope
{
    return new EventEnvelope(
        messageId: $messageId,
        eventType: EventType::OrderCreated,
        aggregateId: 1,
        occurredAt: new DateTimeImmutable('2026-05-01T09:00:00+00:00'),
        payload: EventPayload::fromArray([
            'event_id' => $messageId,
            'order_id' => 1,
            'tracking_code' => 'TRK-2026-000001',
            'sender_id' => 1,
            'status' => 'created',
            'occurred_at' => '2026-05-01T12:00:00+00:00',
            'locale' => 'ru',
            'pickup_district' => 'Северный',
            'transport' => 'light',
            'price_amount' => 19900,
            'price_currency' => 'RUB',
        ]),
    );
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('создаёт отметку с обработчиком и временем обработки', function (): void {
    Carbon::setTestNow('2026-05-01 12:00:00');

    $event = inboxEnvelope();
    $handled = [];

    $result = app(Inbox::class)->process(
        'notifications.order_events',
        $event,
        function (EventEnvelope $received) use (&$handled): void {
            $handled[] = $received->messageId;
        },
    );

    $mark = ProcessedMessage::query()->sole();

    expect($result)->toBe(InboxResult::Processed)
        ->and($handled)->toBe([$event->messageId])
        ->and($mark->message_id)->toBe($event->messageId)
        ->and($mark->handler)->toBe('notifications.order_events')
        ->and($mark->processed_at->utc()->equalTo(now()->utc()))->toBeTrue();
});

it('пропускает повторную обработку без второго эффекта', function (): void {
    $inbox = app(Inbox::class);
    $event = inboxEnvelope();
    $calls = 0;

    $effect = function () use (&$calls): void {
        $calls++;
    };

    expect($inbox->process('notifications.order_events', $event, $effect))->toBe(InboxResult::Processed)
        ->and($inbox->process('notifications.order_events', $event, $effect))->toBe(InboxResult::Skipped)
        ->and($calls)->toBe(1)
        ->and(ProcessedMessage::query()->count())->toBe(1);
});

it('не допускает вторую отметку той же пары «событие — обработчик»', function (): void {
    $event = inboxEnvelope();

    app(Inbox::class)->process('notifications.order_events', $event, static function (): void {});

    expect(static fn () => DB::table('processed_messages')->insert([
        'message_id' => $event->messageId,
        'handler' => 'notifications.order_events',
        'processed_at' => now()->utc()->format('Y-m-d H:i:sP'),
        'created_at' => now()->format('Y-m-d H:i:s'),
    ]))->toThrow(QueryException::class);
});

it('откатывает эффект и отметку при сбое', function (): void {
    $inbox = app(Inbox::class);
    $event = inboxEnvelope();

    expect(static fn () => $inbox->process('notifications.order_events', $event, static function (): void {
        // Эффект успел записать строку — она должна исчезнуть вместе с отметкой.
        DB::table('processed_messages')->insert([
            'message_id' => '9d1f2c3b-4a5e-4f60-8b71-0c2d3e4f5a6b',
            'handler' => 'probe.effect',
            'processed_at' => now()->utc()->format('Y-m-d H:i:sP'),
            'created_at' => now()->format('Y-m-d H:i:s'),
        ]);

        throw new RuntimeException('Сбой эффекта.');
    }))->toThrow(RuntimeException::class);

    expect(ProcessedMessage::query()->count())->toBe(0);

    // Повторная доставка обрабатывает событие заново: потерянных событий нет.
    expect($inbox->process('notifications.order_events', $event, static function (): void {}))
        ->toBe(InboxResult::Processed)
        ->and(ProcessedMessage::query()->count())->toBe(1);
});

it('пропускает обработку, если отметку уже записал другой воркер', function (): void {
    $event = inboxEnvelope();

    ProcessedMessage::factory()->create([
        'message_id' => $event->messageId,
        'handler' => 'notifications.order_events',
    ]);

    $calls = 0;

    $result = app(Inbox::class)->process(
        'notifications.order_events',
        $event,
        function () use (&$calls): void {
            $calls++;
        },
    );

    expect($result)->toBe(InboxResult::Skipped)
        ->and($calls)->toBe(0)
        ->and(ProcessedMessage::query()->count())->toBe(1);
});

it('обрабатывает событие вторым слушателем независимо', function (): void {
    $inbox = app(Inbox::class);
    $event = inboxEnvelope();
    $first = 0;
    $second = 0;

    $inbox->process('notifications.order_events', $event, function () use (&$first): void {
        $first++;
    });

    $result = $inbox->process('tracking.order_events', $event, function () use (&$second): void {
        $second++;
    });

    expect($result)->toBe(InboxResult::Processed)
        ->and($first)->toBe(1)
        ->and($second)->toBe(1)
        ->and(ProcessedMessage::query()->count())->toBe(2);
});
