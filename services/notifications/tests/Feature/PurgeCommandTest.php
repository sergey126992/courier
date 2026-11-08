<?php

declare(strict_types=1);

use App\Models\InboxMessage;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use App\Models\NotificationDedup;
use App\Models\PushMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Очистка журнала по срокам хранения (FR-NTF-267, ЧТЗ 7, п. 8)
|--------------------------------------------------------------------------
|
| У каждой таблицы свой срок из конфигурации: Inbox и ключи дедупликации —
| 30 дней, уведомления и попытки — 90, push-журнал — 30. Очистка не ломает
| ссылки: попытки уходят с уведомлением каскадом, строка push-журнала
| переживает удаление уведомления с обнулённой ссылкой.
|
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00', 'UTC'));
    config([
        'notifications.retention.days' => 90,
        'notifications.retention.attempt_days' => 90,
        'notifications.retention.push_days' => 30,
        'notifications.retention.inbox_days' => 30,
        'notifications.retention.dedup_days' => 30,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Сдвигает момент создания строки в прошлое.
 */
function purgeAge(string $table, int $id, int $days): void
{
    DB::table($table)->where('id', $id)->update([
        'created_at' => now()->utc()->subDays($days)->format('Y-m-d H:i:sP'),
    ]);
}

it('удаляет записи строго по срокам хранения и печатает счётчики', function (): void {
    $oldNotification = Notification::factory()->create();
    purgeAge('notifications', $oldNotification->id, 100);

    $freshNotification = Notification::factory()->create();
    purgeAge('notifications', $freshNotification->id, 10);

    $oldAttempt = NotificationAttempt::factory()->create(['notification_id' => $freshNotification->id]);
    purgeAge('notification_attempts', $oldAttempt->id, 95);

    $inboxOld = InboxMessage::factory()->create();
    purgeAge('inbox_messages', $inboxOld->id, 40);
    $inboxFresh = InboxMessage::factory()->create();
    purgeAge('inbox_messages', $inboxFresh->id, 10);

    $dedupOld = NotificationDedup::factory()->create();
    purgeAge('notification_dedup', $dedupOld->id, 40);
    $dedupFresh = NotificationDedup::factory()->create();
    purgeAge('notification_dedup', $dedupFresh->id, 10);

    $pushOld = PushMessage::factory()->create(['notification_id' => $freshNotification->id]);
    purgeAge('push_messages', $pushOld->id, 40);
    $pushFresh = PushMessage::factory()->create(['notification_id' => $freshNotification->id]);
    purgeAge('push_messages', $pushFresh->id, 10);

    $exit = Artisan::call('notifications:purge', ['--json' => true]);
    $report = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($report['notifications'])->toBe(1)
        ->and($report['notification_attempts'])->toBe(1)
        ->and($report['inbox_messages'])->toBe(1)
        ->and($report['notification_dedup'])->toBe(1)
        ->and($report['push_messages'])->toBe(1)
        ->and($report['days'])->toBe(90)
        ->and($report['attempt_days'])->toBe(90)
        ->and($report['push_days'])->toBe(30)
        ->and($report['inbox_days'])->toBe(30)
        ->and($report['dedup_days'])->toBe(30);

    $this->assertDatabaseMissing('notifications', ['id' => $oldNotification->id]);
    $this->assertDatabaseHas('notifications', ['id' => $freshNotification->id]);
    $this->assertDatabaseMissing('notification_attempts', ['id' => $oldAttempt->id]);
    $this->assertDatabaseMissing('inbox_messages', ['id' => $inboxOld->id]);
    $this->assertDatabaseHas('inbox_messages', ['id' => $inboxFresh->id]);
    $this->assertDatabaseMissing('notification_dedup', ['id' => $dedupOld->id]);
    $this->assertDatabaseHas('notification_dedup', ['id' => $dedupFresh->id]);
    $this->assertDatabaseMissing('push_messages', ['id' => $pushOld->id]);
    $this->assertDatabaseHas('push_messages', ['id' => $pushFresh->id]);
});

it('обнуляет ссылку push-журнала при удалении уведомления, а не удаляет запись', function (): void {
    config(['notifications.retention.push_days' => 365]);

    $notification = Notification::factory()->create();
    purgeAge('notifications', $notification->id, 100);

    $push = PushMessage::factory()->create(['notification_id' => $notification->id]);
    purgeAge('push_messages', $push->id, 10);

    $this->artisan('notifications:purge')->assertExitCode(0);

    $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    $this->assertDatabaseHas('push_messages', ['id' => $push->id, 'notification_id' => null]);
});

it('удаляет попытки вместе с уведомлением каскадом', function (): void {
    $notification = Notification::factory()->create();
    purgeAge('notifications', $notification->id, 100);

    $attempt = NotificationAttempt::factory()->create(['notification_id' => $notification->id]);
    purgeAge('notification_attempts', $attempt->id, 10);

    $this->artisan('notifications:purge')->assertExitCode(0);

    $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);
    $this->assertDatabaseMissing('notification_attempts', ['id' => $attempt->id]);
});

it('переопределяет срок журнала уведомлений флагом --days', function (): void {
    $aged = Notification::factory()->create();
    purgeAge('notifications', $aged->id, 10);

    $fresh = Notification::factory()->create();
    purgeAge('notifications', $fresh->id, 2);

    $exit = Artisan::call('notifications:purge', ['--days' => '5', '--json' => true]);
    $report = json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR);

    expect($exit)->toBe(0)
        ->and($report['notifications'])->toBe(1)
        ->and($report['days'])->toBe(5);

    $this->assertDatabaseMissing('notifications', ['id' => $aged->id]);
    $this->assertDatabaseHas('notifications', ['id' => $fresh->id]);
});

it('отказывает при некорректном сроке хранения', function (): void {
    expect(Artisan::call('notifications:purge', ['--days' => '0']))->toBe(2);
});
