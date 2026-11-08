<?php

declare(strict_types=1);

use App\Domain\Events\EventEnvelope;
use App\Domain\Events\EventPayload;
use App\Domain\Events\EventSchemaVersion;
use App\Domain\Events\EventType;
use App\Models\Order\DeliveryOrder;
use App\Models\Order\OrderNotificationStatus;
use App\Services\Events\LocalEventBus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Потребление обратных событий и проекция статуса (FR-NTF-284, ЧТЗ 9.6 п. 3, 4)
|--------------------------------------------------------------------------
|
| Транспорт — in-memory (phpunit.xml): сообщения кладутся в очередь потока
| уведомлений и обрабатываются командой `notifications:consume-status`.
| Проверяется «ровно один эффект»: повторная доставка того же сообщения не
| создаёт вторую строку проекции, событие не своей проекции подтверждается
| без эффекта и не блокирует поток.
|
*/

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon\Carbon::setTestNow();
});

/**
 * Конверт обратного события сервиса оповещений (ЧТЗ 8.3).
 *
 * @param array<string, mixed> $payload переопределяющие поля payload
 */
function notificationStatusEnvelope(
    EventType $type,
    int $orderId,
    array $payload = [],
    ?string $messageId = null,
): EventEnvelope {
    $messageId ??= (string) Str::uuid();
    $failed = $type === EventType::NotificationFailed;

    $values = array_merge([
        'event_id' => (string) Str::uuid(),
        'notification_id' => 501,
        'order_id' => $orderId,
        'recipient_id' => 7,
        'channel' => 'email',
        'status' => $failed ? 'failed' : 'sent',
        'attempt' => 1,
        'occurred_at' => '2026-11-06T10:00:00+00:00',
        'correlation_id' => 'corr-00000042',
    ], $payload);

    if ($failed && ! array_key_exists('error_code', $values)) {
        $values['error_code'] = 'NOTIFICATION_SEND_FAILED';
    }

    return new EventEnvelope(
        messageId: $messageId,
        eventType: $type,
        aggregateId: (int) ($values['notification_id'] ?? 0),
        occurredAt: new DateTimeImmutable('2026-11-06T10:00:00+00:00'),
        payload: EventPayload::fromArray($values),
        schemaVersion: EventSchemaVersion::V1,
        correlationId: 'corr-00000042',
    );
}

/**
 * Кладёт событие в поток обратных событий режима in-memory.
 */
function enqueueNotificationStatus(EventEnvelope $event): void
{
    app(LocalEventBus::class)->enqueue(
        (string) config('events.streams.notifications'),
        $event,
        $event->messageId,
    );
}

it('проецирует обратное событие об успешной доставке', function (): void {
    $order = DeliveryOrder::factory()->create();
    $event = notificationStatusEnvelope(EventType::NotificationSent, $order->id, [
        'notification_id' => 501,
        'channel' => 'email',
    ]);

    enqueueNotificationStatus($event);

    $this->artisan('notifications:consume-status', ['--once' => true])->assertExitCode(0);

    $this->assertDatabaseHas('order_notification_status', [
        'order_id' => $order->id,
        'notification_id' => 501,
        'channel' => 'email',
        'status' => 'sent',
        'attempt' => 1,
        'error_code' => null,
        'source_event_id' => $event->messageId,
    ]);
});

it('пишет correlation_id исходного запроса в журнал проекции', function (): void {
    /** @var ArrayObject<int, MessageLogged> $logs */
    $logs = new ArrayObject;

    Event::listen(MessageLogged::class, static function (MessageLogged $record) use ($logs): void {
        $logs->append($record);
    });

    $order = DeliveryOrder::factory()->create();
    $event = notificationStatusEnvelope(EventType::NotificationSent, $order->id, [
        'notification_id' => 501,
        'channel' => 'email',
    ]);

    enqueueNotificationStatus($event);

    $this->artisan('notifications:consume-status', ['--once' => true])->assertExitCode(0);

    $record = collect($logs->getArrayCopy())->first(
        fn (MessageLogged $record): bool => $record->message === 'Проекция статуса доставки применена.',
    );

    expect($record)->not->toBeNull();

    /** @var MessageLogged $record */
    expect($record->context)->toMatchArray([
        'correlation_id' => 'corr-00000042',
        'event_id' => $event->messageId,
        'order_id' => $order->id,
        'notification_id' => 501,
        'channel' => 'email',
        'status' => 'sent',
        'attempt' => 1,
    ]);
});

it('проецирует окончательный отказ с кодом причины', function (): void {
    $order = DeliveryOrder::factory()->create();
    $event = notificationStatusEnvelope(EventType::NotificationFailed, $order->id, [
        'notification_id' => 502,
        'channel' => 'push',
        'error_code' => 'NOTIFICATION_RECIPIENT_UNKNOWN',
    ]);

    enqueueNotificationStatus($event);

    $this->artisan('notifications:consume-status', ['--once' => true])->assertExitCode(0);

    $this->assertDatabaseHas('order_notification_status', [
        'order_id' => $order->id,
        'notification_id' => 502,
        'channel' => 'push',
        'status' => 'failed',
        'error_code' => 'NOTIFICATION_RECIPIENT_UNKNOWN',
    ]);
});

it('не создаёт вторую строку при повторе того же обратного события', function (): void {
    $order = DeliveryOrder::factory()->create();
    $event = notificationStatusEnvelope(EventType::NotificationSent, $order->id);

    for ($pass = 0; $pass < 2; $pass++) {
        // Повторная доставка: тот же message_id, то же событие (at-least-once).
        enqueueNotificationStatus($event);

        $this->artisan('notifications:consume-status', ['--once' => true])->assertExitCode(0);
    }

    expect(OrderNotificationStatus::query()->where('order_id', $order->id)->count())->toBe(1);
});

it('подтверждает событие не своей проекции без эффекта', function (): void {
    $order = DeliveryOrder::factory()->create();

    $event = new EventEnvelope(
        messageId: (string) Str::uuid(),
        eventType: EventType::OrderDelivered,
        aggregateId: $order->id,
        occurredAt: new DateTimeImmutable('2026-11-06T10:00:00+00:00'),
        payload: EventPayload::fromArray([
            'event_id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'tracking_code' => 'TRK-42',
            'sender_id' => 7,
            'status' => 'delivered',
            'occurred_at' => '2026-11-06T10:00:00+00:00',
            'locale' => 'ru',
            'assignment_id' => 1,
            'courier_id' => 2,
            'delivered_at' => '2026-11-06T10:00:00+00:00',
        ]),
    );

    enqueueNotificationStatus($event);

    $this->artisan('notifications:consume-status', ['--once' => true])->assertExitCode(0);

    expect(OrderNotificationStatus::query()->count())->toBe(0);
});

it('ставит проход потребителя в расписание с интервалом из конфигурации', function (): void {
    expect(Artisan::call('schedule:list', ['--json' => true]))->toBe(0);

    /** @var list<array<string, mixed>> $events */
    $events = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    $consumer = null;

    foreach ($events as $entry) {
        $command = (string) ($entry['command'] ?? '');

        if (str_contains($command, 'notifications:consume-status')) {
            $consumer = $entry;

            break;
        }
    }

    if ($consumer === null) {
        throw new RuntimeException('Задача потребителя обратных событий отсутствует в расписании.');
    }

    $interval = max(1, (int) config('events.status_consume_interval_seconds'));
    $minutes = min(59, max(1, intdiv($interval + 59, 60)));

    expect($consumer['description'])->toBe('events:consume-notification-status')
        ->and((string) $consumer['expression'])->toBe(sprintf('*/%d * * * *', $minutes));
});
