<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationStatus;
use App\Models\InboxMessage;
use App\Models\Notification;
use App\Models\NotificationDedup;
use App\Models\RecipientContact;
use App\Services\Events\StreamMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Потребитель событий сервиса (FR-NTF-224…227, FR-NTF-261…266, ЧТЗ 9.2)
|--------------------------------------------------------------------------
|
| Транспорт подменён подставным потоком: проверки не ходят в Redis.
| Сообщение подтверждается только после COMMIT эффекта, повтор и гонка
| не создают второй строки, недействительное сообщение подтверждается
| без эффекта и не блокирует поток.
|
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Канал email доступен заранее только при наличии контакта
    // (FR-NTF-440, FR-NTF-474): в рабочем окружении его пишет поток контактов.
    RecipientContact::factory()->create(['user_id' => 7, 'email' => 'sender@example.test', 'locale' => 'ru']);
});

it('создаёт уведомления по событию доставки и подтверждает сообщение', function (): void {
    $eventId = Str::uuid()->toString();
    $stream = fakeEventStream([
        notificationStreamMessage('order.delivered', ['courier_id' => 55, 'assignment_id' => 3], $eventId),
    ]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    expect(Notification::query()->count())->toBe(2)
        ->and($stream->acknowledged)->toBe(['0-1'])
        ->and(InboxMessage::query()->count())->toBe(1)
        ->and(NotificationDedup::query()->count())->toBe(2)
        ->and(NotificationDedup::query()->whereNotNull('notification_id')->count())->toBe(2);

    $this->assertDatabaseHas('notifications', [
        'event_id' => $eventId,
        'recipient_id' => 7,
        'recipient_role' => 'sender',
        'channel' => 'email',
        'event_type' => 'order.delivered',
        'template' => 'order_delivered_email',
        'status' => NotificationStatus::Created->value,
    ]);
    $this->assertDatabaseHas('notifications', [
        'event_id' => $eventId,
        'recipient_id' => 7,
        'channel' => 'push',
    ]);
});

it('не создаёт вторую строку при повторной доставке сообщения', function (): void {
    $message = notificationStreamMessage('order.delivered', ['courier_id' => 55, 'assignment_id' => 3]);
    $stream = fakeEventStream([$message, $message]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    expect(Notification::query()->count())->toBe(2)
        ->and(InboxMessage::query()->count())->toBe(1)
        ->and($stream->acknowledged)->toBe(['0-1', '0-1']);
});

it('не создаёт вторую строку при повторной публикации события', function (): void {
    $eventId = Str::uuid()->toString();
    $stream = fakeEventStream([
        notificationStreamMessage('order.delivered', ['courier_id' => 55, 'assignment_id' => 3], $eventId, '0-1'),
        notificationStreamMessage('order.delivered', ['courier_id' => 55, 'assignment_id' => 3], $eventId, '0-2'),
    ]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    expect(Notification::query()->count())->toBe(2)
        ->and(NotificationDedup::query()->count())->toBe(2);
});

it('помечает сообщение недействительным при неполном payload', function (): void {
    $messageId = Str::uuid()->toString();
    $fields = notificationEventFields('order.delivered', ['courier_id' => 55, 'assignment_id' => 3], $messageId);
    $payload = json_decode($fields['payload'], true);
    unset($payload['sender_id']);
    $fields['payload'] = (string) json_encode($payload);

    $stream = fakeEventStream([new StreamMessage('0-1', $fields)]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    expect(Notification::query()->count())->toBe(0)
        ->and($stream->acknowledged)->toBe(['0-1'])
        ->and(InboxMessage::query()->value('status'))->toBe('invalid')
        ->and(InboxMessage::query()->value('error_code'))->toBe('EVENT_CONTRACT_INVALID');
});

it('помечает сообщение недействительным при неизвестной версии контракта', function (): void {
    $fields = notificationEventFields();
    $fields['schema_version'] = 'v2';

    $stream = fakeEventStream([new StreamMessage('0-1', $fields)]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    expect($stream->acknowledged)->toBe(['0-1'])
        ->and(InboxMessage::query()->value('error_code'))->toBe('EVENT_CONTRACT_UNKNOWN')
        ->and(Notification::query()->count())->toBe(0);
});

it('не роняет потребитель на неизвестном типе события', function (): void {
    $fields = notificationEventFields('order.unknown');
    $stream = fakeEventStream([new StreamMessage('0-1', $fields)]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    expect($stream->acknowledged)->toBe(['0-1'])
        ->and(InboxMessage::query()->value('error_code'))->toBe('EVENT_CONTRACT_UNKNOWN');
});

it('подтверждает событие без правил и не пополняет журнал', function (): void {
    $fields = [
        'message_id' => Str::uuid()->toString(),
        'event_type' => 'courier.shift.started',
        'schema_version' => 'v1',
        'occurred_at' => '2026-11-06T10:00:00+00:00',
        'aggregate_type' => 'courier_shift',
        'aggregate_id' => '9',
        'payload' => (string) json_encode(['shift_id' => 9, 'courier_id' => 55]),
    ];

    $stream = fakeEventStream([new StreamMessage('0-1', $fields)]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    expect($stream->acknowledged)->toBe(['0-1'])
        ->and(Notification::query()->count())->toBe(0)
        ->and(NotificationDedup::query()->count())->toBe(0);
});

it('не создаёт push курьеру по отмене без назначения', function (): void {
    $stream = fakeEventStream([notificationStreamMessage('order.cancelled')]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    expect(Notification::query()->count())->toBe(1)
        ->and(Notification::query()->value('channel'))->toBe('email');
});

it('создаёт push курьеру по отмене при наличии назначения', function (): void {
    Notification::factory()->create([
        'order_id' => 42,
        'recipient_id' => 55,
        'recipient_role' => 'courier',
        'channel' => 'push',
        'event_type' => 'order.assigned',
    ]);

    $stream = fakeEventStream([notificationStreamMessage('order.cancelled')]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    expect(Notification::query()->where('event_type', 'order.cancelled')->count())->toBe(2);
    $this->assertDatabaseHas('notifications', [
        'event_id' => Notification::query()->where('event_type', 'order.cancelled')->value('event_id'),
        'recipient_id' => 55,
        'recipient_role' => 'courier',
        'channel' => 'push',
        'template' => 'order_cancelled_push',
    ]);
});

it('создаёт уведомления только по включённым каналам', function (): void {
    config(['notifications.enabled_channels' => ['email']]);

    $stream = fakeEventStream([
        notificationStreamMessage('order.delivered', ['courier_id' => 55, 'assignment_id' => 3]),
    ]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    expect(Notification::query()->count())->toBe(1)
        ->and(Notification::query()->value('channel'))->toBe('email');
});

it('забирает зависшие сообщения после порога ожидания', function (): void {
    $stream = fakeEventStream([], [notificationStreamMessage('order.assigned', ['courier_id' => 55, 'assignment_id' => 3])]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(0);

    expect($stream->reclaimIdleSeconds)->toBe([(int) config('notifications.transport.pending_idle_seconds')])
        ->and($stream->acknowledged)->toBe(['0-1'])
        ->and(Notification::query()->count())->toBe(2);
});

it('ограничивает пачку ключом --limit', function (): void {
    $stream = fakeEventStream([
        notificationStreamMessage('order.created', [], null, '0-1'),
        notificationStreamMessage('order.created', ['order_id' => 43], null, '0-2'),
    ]);

    $this->artisan('notifications:consume', ['--once' => true, '--limit' => 1])->assertExitCode(0);

    expect($stream->readLimits)->toBe([1])
        ->and($stream->acknowledged)->toBe(['0-1']);
});

it('не подтверждает сообщение при сбое эффекта', function (): void {
    // Таблица журнала недоступна: эффект не может зафиксироваться, поэтому
    // сообщение обязано остаться в pending и не получить ack (FR-NTF-265).
    Schema::rename('notifications', 'notifications_unavailable');

    $stream = fakeEventStream([notificationStreamMessage('order.delivered', ['courier_id' => 55, 'assignment_id' => 3])]);

    $this->artisan('notifications:consume', ['--once' => true])->assertExitCode(1);

    expect($stream->acknowledged)->toBe([]);
});

it('сообщает degraded при недоступном транспорте', function (): void {
    $stream = fakeEventStream();
    $stream->available = false;

    $this->artisan('notifications:consume', ['--once' => true, '--json' => true])
        ->expectsOutputToContain('"degraded":true')
        ->assertExitCode(1);
});

it('выводит отчёт прохода в JSON', function (): void {
    fakeEventStream([notificationStreamMessage('order.delivered', ['courier_id' => 55, 'assignment_id' => 3])]);

    $this->artisan('notifications:consume', ['--once' => true, '--json' => true])
        ->expectsOutputToContain('"created":2')
        ->assertExitCode(0);
});
