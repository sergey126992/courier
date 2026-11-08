<?php

declare(strict_types=1);

use App\Domain\Notifications\DlqState;
use App\Domain\Notifications\Exceptions\DlqEntryNotReplayable;
use App\Domain\Notifications\NotificationAttemptClass;
use App\Domain\Notifications\NotificationErrorCode;
use App\Domain\Notifications\NotificationStatus;
use App\Models\DlqEntry;
use App\Models\Notification;
use App\Models\RecipientContact;
use App\Services\Notifications\BackoffSchedule;
use App\Services\Notifications\DlqService;
use App\Services\Notifications\NotificationSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
|--------------------------------------------------------------------------
| Очередь необработанных: запись, повтор и очистка (ЧТЗ 9.3)
|--------------------------------------------------------------------------
|
| Терминальный отказ канала создаёт запись DLQ в той же транзакции, что
| `notifications.status=failed` и событие Outbox. Повтор возвращает ту же
| строку журнала в `error`, успешная отправка закрывает запись в `resolved`
| в одной транзакции с `sent`. Внешней сети нет: почта подменена `Mail::fake`,
| время фиксируется `Carbon::setTestNow` (ЧТЗ 9.7).
|
*/

uses(RefreshDatabase::class);

/**
 * Уведомление канала email без контакта получателя: отправка падает
 * бизнес-ошибкой и переводит строку в `failed`.
 *
 * @param array<string, mixed> $attributes переопределяющие поля журнала
 */
function dlqFailingNotification(int $recipientId = 900, array $attributes = []): Notification
{
    return Notification::factory()->create(array_merge([
        'recipient_id' => $recipientId,
        'order_id' => 42,
        'tracking_code' => 'TRK-42',
    ], $attributes));
}

/**
 * Запись DLQ для уже существующей строки журнала.
 */
function dlqEntryForNotification(Notification $notification): DlqEntry
{
    return DlqEntry::factory()->create([
        'notification_id' => $notification->id,
        'channel' => (string) $notification->channel,
        'event_id' => $notification->event_id,
        'event_type' => (string) $notification->event_type,
        'order_id' => $notification->order_id,
        'recipient_id' => (int) $notification->recipient_id,
        'recipient_role' => (string) $notification->recipient_role,
    ]);
}

it('пишет запись DLQ в одной транзакции с failed', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));

    $notification = dlqFailingNotification();
    $status = app(NotificationSender::class)->send($notification);

    expect($status)->toBe(NotificationStatus::Failed)
        ->and(DB::table('dlq_entries')->count())->toBe(1);

    $entry = DlqEntry::query()->sole();

    expect($entry->notification_id)->toBe($notification->id)
        ->and($entry->channel)->toBe('email')
        ->and($entry->state)->toBe(DlqState::Open)
        ->and($entry->event_id)->toBe($notification->event_id)
        ->and($entry->event_type)->toBe((string) $notification->event_type)
        ->and($entry->order_id)->toBe(42)
        ->and($entry->recipient_id)->toBe(900)
        ->and($entry->recipient_role)->toBe((string) $notification->recipient_role)
        ->and($entry->error_class)->toBe(NotificationAttemptClass::BUSINESS)
        ->and($entry->error_code)->toBe(NotificationErrorCode::RECIPIENT_UNKNOWN)
        ->and($entry->attempts)->toBe(1)
        ->and($entry->failed_at->toIso8601String())->toBe('2026-11-07T10:00:00+00:00')
        ->and($entry->replay_count)->toBe(0)
        ->and($entry->last_replayed_at)->toBeNull()
        ->and($entry->resolved_at)->toBeNull();
});

it('не оставляет записи DLQ при откате транзакции', function (): void {
    $notification = dlqFailingNotification();

    try {
        DB::transaction(function () use ($notification): void {
            app(NotificationSender::class)->send($notification);

            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
        // Транзакция откатывается целиком: статуса, события и записи DLQ нет.
    }

    expect(DlqEntry::query()->count())->toBe(0)
        ->and(DB::table('notifications')->where('id', $notification->id)->value('status'))
        ->toBe(NotificationStatus::Created->value);
});

it('запрещает дубль записи по паре notification_id и channel', function (): void {
    $entry = dlqEntryForNotification(dlqFailingNotification());

    expectDatabaseRejection(function () use ($entry): void {
        DlqEntry::factory()->create([
            'notification_id' => $entry->notification_id,
            'channel' => $entry->channel,
        ]);
    });
});

it('запрещает состояние DLQ вне CHECK', function (): void {
    expectDatabaseRejection(function (): void {
        DB::table('dlq_entries')->insert([
            'notification_id' => 1,
            'channel' => 'email',
            'state' => 'bogus',
            'event_id' => '0b0f0f9e-0000-4000-8000-000000000000',
            'event_type' => 'order.delivered',
            'recipient_id' => 7,
            'recipient_role' => 'sender',
            'failed_at' => now(),
        ]);
    });
});

it('держит уникальный индекс и индексы выборки без внешнего ключа на журнал', function (): void {
    /** @var array<string, string> $indexes */
    $indexes = DB::table('pg_indexes')
        ->where('tablename', 'dlq_entries')
        ->pluck('indexdef', 'indexname')
        ->map(static fn (mixed $definition): string => (string) $definition)
        ->all();

    expect($indexes['dlq_entries_notification_id_channel_unique'] ?? '')->toContain('(notification_id, channel)')
        ->and($indexes['dlq_entries_notification_id_channel_unique'] ?? '')->toContain('CREATE UNIQUE INDEX')
        ->and($indexes['dlq_entries_channel_failed_at_desc_index'] ?? '')->toContain('(channel, failed_at DESC)')
        ->and($indexes['dlq_entries_state_failed_at_index'] ?? '')->toContain('(state, failed_at)')
        ->and($indexes['dlq_entries_resolved_at_index'] ?? '')->toContain('(resolved_at)');

    $foreignKeys = DB::table('pg_constraint as c')
        ->join('pg_class as src', 'src.oid', '=', 'c.conrelid')
        ->where('src.relname', 'dlq_entries')
        ->where('c.contype', 'f')
        ->count();

    expect($foreignKeys)->toBe(0);
});

it('ставит ту же строку журнала в error с плановой задержкой и не создаёт вторых записей', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));

    $notification = dlqFailingNotification(901);
    app(NotificationSender::class)->send($notification);
    $entry = DlqEntry::query()->sole();

    $expectedDelay = app(BackoffSchedule::class)->delayFor(1, $notification->id);
    $replayed = app(DlqService::class)->replay($entry->id);

    $raw = DB::table('notifications')->where('id', $notification->id)->value('next_attempt_at');
    $expected = Carbon::parse('2026-11-07 10:00:00', 'UTC')->addSeconds($expectedDelay);

    expect(DlqEntry::query()->count())->toBe(1)
        ->and(Notification::query()->count())->toBe(1)
        ->and($replayed->id)->toBe($entry->id)
        ->and($replayed->state)->toBe(DlqState::Replayed)
        ->and($replayed->replay_count)->toBe(1)
        ->and($replayed->last_replayed_at?->toIso8601String())->toBe('2026-11-07T10:00:00+00:00')
        ->and(DB::table('notifications')->where('id', $notification->id)->value('status'))
        ->toBe(NotificationStatus::Error->value)
        ->and(Carbon::parse((string) $raw)->equalTo($expected))->toBeTrue();
});

it('при повторном отказе обновляет ту же запись и не сбрасывает счётчик повторов', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));

    $notification = dlqFailingNotification(902);
    app(NotificationSender::class)->send($notification);
    $entry = DlqEntry::query()->sole();

    app(DlqService::class)->replay($entry->id);

    Carbon::setTestNow(Carbon::parse('2026-11-07 10:05:00', 'UTC'));
    app(NotificationSender::class)->send($notification->refresh());

    $fresh = DlqEntry::query()->sole();

    expect(DlqEntry::query()->count())->toBe(1)
        ->and($fresh->id)->toBe($entry->id)
        ->and($fresh->state)->toBe(DlqState::Open)
        ->and($fresh->attempts)->toBe(2)
        ->and($fresh->replay_count)->toBe(1)
        ->and($fresh->failed_at?->toIso8601String())->toBe('2026-11-07T10:05:00+00:00')
        ->and($fresh->resolved_at)->toBeNull();
});

it('переводит запись в resolved в одной транзакции с sent', function (): void {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));

    $notification = dlqFailingNotification(903);
    app(NotificationSender::class)->send($notification);
    $entry = DlqEntry::query()->sole();
    app(DlqService::class)->replay($entry->id);

    RecipientContact::factory()->create([
        'user_id' => 903,
        'email' => 'sender@example.test',
        'locale' => 'ru',
    ]);

    Carbon::setTestNow(Carbon::parse('2026-11-07 10:01:00', 'UTC'));
    $status = app(NotificationSender::class)->send($notification->refresh());

    $fresh = DlqEntry::query()->sole();

    expect($status)->toBe(NotificationStatus::Sent)
        ->and($fresh->id)->toBe($entry->id)
        ->and($fresh->state)->toBe(DlqState::Resolved)
        ->and($fresh->resolved_at?->toIso8601String())->toBe('2026-11-07T10:01:00+00:00')
        ->and(DB::table('notifications')->where('id', $notification->id)->value('status'))
        ->toBe(NotificationStatus::Sent->value);
});

it('отклоняет повтор разобранной записи', function (): void {
    $entry = dlqEntryForNotification(dlqFailingNotification(904));
    $entry->forceFill(['state' => DlqState::Resolved, 'resolved_at' => now()])->save();

    expect(fn (): DlqEntry => app(DlqService::class)->replay((int) $entry->id))
        ->toThrow(DlqEntryNotReplayable::class)
        ->and(DB::table('dlq_entries')->where('id', $entry->id)->value('state'))
        ->toBe(DlqState::Resolved->value);
});

it('держит число записей DLQ равным числу терминальных отказов по каналам', function (): void {
    $notifications = [
        dlqFailingNotification(910),
        dlqFailingNotification(911),
        dlqFailingNotification(912),
        dlqFailingNotification(913, ['channel' => 'push', 'event_type' => 'order.delivered', 'template' => 'order_delivered_push']),
    ];

    foreach ($notifications as $notification) {
        app(NotificationSender::class)->send($notification);
    }

    $failedPairs = Notification::query()
        ->where('status', NotificationStatus::Failed->value)
        ->get()
        ->map(static fn (Notification $row): string => $row->id.'|'.(string) $row->channel)
        ->sort()
        ->values()
        ->all();

    $entryPairs = DlqEntry::query()
        ->get()
        ->map(static fn (DlqEntry $row): string => $row->notification_id.'|'.(string) $row->channel)
        ->sort()
        ->values()
        ->all();

    expect($failedPairs)->not->toBeEmpty()
        ->and($entryPairs)->toBe($failedPairs)
        ->and(DlqEntry::query()->count())->toBe(count($failedPairs));
});

it('удаляет записи DLQ старше срока хранения и не трогает журнал уведомлений', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));

    $old = dlqEntryForNotification(dlqFailingNotification(920));
    $old->forceFill(['failed_at' => now()->subDays(91)])->save();
    $fresh = dlqEntryForNotification(dlqFailingNotification(921));
    $fresh->forceFill(['failed_at' => now()->subDays(10)])->save();

    $notificationCount = Notification::query()->count();
    $deleted = app(DlqService::class)->purgeExpired();

    expect($deleted)->toBe(1)
        ->and(DlqEntry::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(DlqEntry::query()->whereKey($fresh->id)->exists())->toBeTrue()
        ->and(Notification::query()->count())->toBe($notificationCount);
});
