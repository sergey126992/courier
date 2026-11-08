<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationErrorCode;
use App\Domain\Notifications\NotificationStatus;
use App\Models\Notification;
use App\Models\OutboxMessage;
use App\Models\RecipientContact;
use App\Services\Notifications\NotificationEventOutbox;
use App\Services\Notifications\NotificationSender;
use App\Services\Notifications\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Outbox сервиса и обратные события (FR-NTF-281…286, ЧТЗ 9.3 п. 1, 2, 5)
|--------------------------------------------------------------------------
|
| Проверяется: терминальный статус пишет обратное событие в Outbox сервиса,
| повторы (`error`) событий не порождают, сбой публикации не отменяет факт
| отправки, `published_at` ставится только после принятия записи транспортом.
| Redis и почта подменены: внешней сети нет (ЧТЗ 9.7).
|
*/

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Уведомление email с готовым контактом получателя.
 *
 * @param array<string, mixed> $attributes переопределяющие поля журнала
 */
function outboxNotification(array $attributes = []): Notification
{
    RecipientContact::factory()->create([
        'user_id' => 7,
        'email' => 'sender@example.test',
        'locale' => 'ru',
    ]);

    return Notification::factory()->create(array_merge([
        'recipient_id' => 7,
        'order_id' => 42,
        'tracking_code' => 'TRK-42',
        'correlation_id' => 'corr-00000042',
    ], $attributes));
}

it('пишет NotificationSent.v1 в Outbox вместе с переходом в sent', function (): void {
    Mail::fake();

    $notification = outboxNotification();

    expect(app(NotificationSender::class)->send($notification))->toBe(NotificationStatus::Sent);

    $event = OutboxMessage::query()->sole();
    $payload = $event->payload;

    expect($event->event_type)->toBe('notification.sent')
        ->and($event->schema_version)->toBe('v1')
        ->and($event->aggregate_type)->toBe('notification')
        ->and($event->aggregate_id)->toBe((int) $notification->getKey())
        ->and($event->correlation_id)->toBe('corr-00000042')
        ->and($event->published_at)->toBeNull()
        ->and($event->attempts)->toBe(0)
        ->and($payload['event_id'])->toBe($notification->event_id)
        ->and($payload['notification_id'])->toBe((int) $notification->getKey())
        ->and($payload['order_id'])->toBe(42)
        ->and($payload['recipient_id'])->toBe(7)
        ->and($payload['channel'])->toBe('email')
        ->and($payload['status'])->toBe('sent')
        ->and($payload['attempt'])->toBe(1)
        ->and($payload['correlation_id'])->toBe('corr-00000042')
        ->and($payload)->not->toHaveKey('error_code')
        ->and($payload)->not->toHaveKey('email');
});

it('пишет NotificationFailed.v1 при окончательном отказе', function (): void {
    Mail::fake();

    // Контакта нет: причина бизнесовая, повтор не выполняется (FR-NTF-271).
    $notification = Notification::factory()->create([
        'recipient_id' => 999,
        'order_id' => 42,
        'correlation_id' => 'corr-00000042',
    ]);

    expect(app(NotificationSender::class)->send($notification))->toBe(NotificationStatus::Failed);

    $event = OutboxMessage::query()->sole();

    expect($event->event_type)->toBe('notification.failed')
        ->and($event->payload['status'])->toBe('failed')
        ->and($event->payload['error_code'])->toBe(NotificationErrorCode::RECIPIENT_UNKNOWN);
});

it('не пишет обратное событие, пока повтор возможен', function (): void {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp down'));

    $notification = outboxNotification();

    expect(app(NotificationSender::class)->send($notification))->toBe(NotificationStatus::Error)
        ->and(OutboxMessage::query()->count())->toBe(0);
});

it('публикует обратное событие в поток событий уведомлений', function (): void {
    Mail::fake();

    $stream = fakeEventStream();
    $notification = outboxNotification();

    app(NotificationSender::class)->send($notification);

    $result = app(OutboxPublisher::class)->run();

    expect($result->published)->toBe(1)
        ->and($result->failed)->toBe(0)
        ->and($result->lockBusy)->toBeFalse();

    $event = OutboxMessage::query()->sole();

    expect($event->published_at)->not->toBeNull()
        ->and($stream->published)->toHaveCount(1);

    $message = $stream->published[0];
    $payload = json_decode($message['fields']['payload'], true, 512, JSON_THROW_ON_ERROR);

    expect($message['stream'])->toBe((string) config('notifications.transport.streams.notifications'))
        ->and($message['fields']['event_type'])->toBe('notification.sent')
        ->and($message['fields']['schema_version'])->toBe('v1')
        ->and($message['fields']['correlation_id'])->toBe('corr-00000042')
        ->and($payload['notification_id'])->toBe((int) $notification->getKey())
        ->and($payload['status'])->toBe('sent');
});

it('повторный проход не публикует событие дважды', function (): void {
    Mail::fake();

    $stream = fakeEventStream();

    app(NotificationSender::class)->send(outboxNotification());
    app(OutboxPublisher::class)->run();

    $second = app(OutboxPublisher::class)->run();

    expect($second->published)->toBe(0)
        ->and($stream->published)->toHaveCount(1);
});

it('оставляет событие в Outbox при сбое транспорта и не отменяет отправку', function (): void {
    Mail::fake();

    $stream = fakeEventStream();
    $stream->available = false;

    $notification = outboxNotification();
    app(NotificationSender::class)->send($notification);

    $result = app(OutboxPublisher::class)->run();
    $event = OutboxMessage::query()->sole();

    expect($result->published)->toBe(0)
        ->and($result->failed)->toBe(1)
        ->and($event->published_at)->toBeNull()
        ->and($event->attempts)->toBe(1)
        ->and($event->last_error)->toBe('NOTIFICATION_OUTBOX_PUBLISH_FAILED')
        ->and($event->available_at->greaterThan(now()))->toBeTrue()
        // Факт отправки не отменяется сбоем публикации (FR-NTF-286).
        ->and($notification->refresh()->status)->toBe(NotificationStatus::Sent);
});

it('не публикует событие, не соответствующее общей схеме', function (): void {
    $stream = fakeEventStream();

    $event = OutboxMessage::factory()->create([
        'event_type' => 'notification.sent',
        'payload' => ['notification_id' => 1],
    ]);

    $result = app(OutboxPublisher::class)->run();
    $event->refresh();

    expect($result->published)->toBe(0)
        ->and($result->failed)->toBe(1)
        ->and($stream->published)->toBe([])
        ->and($event->published_at)->toBeNull()
        ->and($event->last_error)->toBe(NotificationErrorCode::CONTRACT_INVALID)
        ->and($event->attempts)->toBe((int) config('notifications.outbox.publish_max_attempts'));
});

it('публикует накопленную пачку после восстановления Redis без дублей', function (): void {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00', 'UTC'));
    config(['notifications.outbox.publish_backoff_seconds' => [5]]);

    RecipientContact::factory()->create([
        'user_id' => 7,
        'email' => 'sender@example.test',
        'locale' => 'ru',
    ]);

    foreach ([41, 42] as $orderId) {
        Notification::factory()->create([
            'recipient_id' => 7,
            'order_id' => $orderId,
            'tracking_code' => 'TRK-'.$orderId,
            'correlation_id' => 'corr-000000'.$orderId,
        ]);
    }

    // Redis недоступен: ни одна строка не получает published_at (FR-NTF-203).
    $stream = fakeEventStream();
    $stream->available = false;

    foreach (Notification::query()->orderBy('id')->get() as $notification) {
        app(NotificationSender::class)->send($notification);
    }

    $down = app(OutboxPublisher::class)->run();

    expect($down->published)->toBe(0)
        ->and($down->failed)->toBe(2)
        ->and(OutboxMessage::query()->whereNotNull('published_at')->count())->toBe(0)
        ->and(OutboxMessage::query()->where('attempts', 1)->count())->toBe(2)
        ->and($stream->published)->toBe([]);

    // Redis восстановлен: пачка уходит целиком и только один раз (FR-NTF-210).
    $stream->available = true;
    Carbon::setTestNow(now()->addSeconds(5));

    $first = app(OutboxPublisher::class)->run();
    $second = app(OutboxPublisher::class)->run();

    expect($first->published)->toBe(2)
        ->and($second->published)->toBe(0)
        ->and($stream->published)->toHaveCount(2)
        ->and(OutboxMessage::query()->whereNotNull('published_at')->count())->toBe(2);
});

it('завершает проход команды публикатора успешно', function (): void {
    fakeEventStream();
    Mail::fake();

    app(NotificationSender::class)->send(outboxNotification());

    $this->artisan('notifications:publish-outbox')
        ->expectsOutput('Опубликовано событий: 1, неудачных: 0.')
        ->assertExitCode(0);

    expect(OutboxMessage::query()->sole()->published_at)->not->toBeNull();
});

it('не создаёт обратное событие для уведомления без заказа', function (): void {
    $notification = Notification::factory()->create(['order_id' => null]);

    app(NotificationEventOutbox::class)->enqueue($notification, NotificationStatus::Sent, 1, null);

    expect(OutboxMessage::query()->count())->toBe(0);
});
