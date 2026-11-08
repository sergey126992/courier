<?php

declare(strict_types=1);

use App\Domain\Notifications\NotificationErrorCode;
use App\Domain\Notifications\NotificationStatus;
use App\Mail\NotificationMail;
use App\Models\ChannelBinding;
use App\Models\Notification;
use App\Models\NotificationAttempt;
use App\Models\PushMessage;
use App\Models\RecipientContact;
use App\Services\Notifications\Channels\Contracts\TelegramBotClient;
use App\Services\Notifications\Channels\FakeTelegramBotClient;
use App\Services\Notifications\Channels\TelegramApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Поллер отправки, попытки, backoff и необработанные (FR-NTF-271…277)
|--------------------------------------------------------------------------
|
| Время фиксируется Carbon, поэтому паузы backoff проверяются без sleep.
| Почта подменена, push пишется в журнал симулятора, сети нет (ЧТЗ 9.7).
|
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:00', 'UTC'));
    config([
        'notifications.sender.backoff_seconds' => [300],
        'notifications.sender.backoff_jitter_ratio' => 0.0,
        'notifications.sender.max_attempts' => 5,
        'notifications.sender.batch_size' => 50,
    ]);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Фиксирует моменты строки журнала в UTC.
 *
 * Часовой пояс приложения в контейнере — Europe/Moscow, а колонки хранятся
 * в timestamptz: тесту нужны абсолютные моменты, не зависящие от пояса.
 */
function pollerFixTimestamps(int $notificationId, string $moment): void
{
    DB::table('notifications')->where('id', $notificationId)->update([
        'created_at' => $moment,
        'updated_at' => $moment,
    ]);
}

/**
 * Дописывает строку журнала попыток с абсолютным моментом.
 */
function pollerAttemptAt(
    int $notificationId,
    int $attempt,
    string $status,
    string $moment,
    ?string $errorClass = null,
    ?string $errorCode = null,
): void {
    NotificationAttempt::query()->insert([
        'notification_id' => $notificationId,
        'attempt' => $attempt,
        'status' => $status,
        'error_class' => $errorClass,
        'error_code' => $errorCode,
        'error_message' => null,
        'provider_code' => null,
        'created_at' => $moment,
    ]);
}

/**
 * Контакт отправителя для письма.
 */
function pollerContact(int $userId = 7): void
{
    RecipientContact::factory()->create([
        'user_id' => $userId,
        'email' => 'sender@example.test',
        'locale' => 'ru',
    ]);
}

it('отправляет готовое уведомление и пишет попытку в журнал', function (): void {
    pollerContact();
    $notification = Notification::factory()->create(['recipient_id' => 7, 'order_id' => 42]);

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $notification->refresh();

    expect($notification->status)->toBe(NotificationStatus::Sent)
        ->and($notification->attempts)->toBe(1)
        ->and($notification->sent_at)->not->toBeNull()
        ->and($notification->last_error_code)->toBeNull();

    $this->assertDatabaseHas('notification_attempts', [
        'notification_id' => $notification->id,
        'attempt' => 1,
        'status' => 'sent',
        'error_class' => null,
        'error_code' => null,
        'provider_code' => null,
    ]);

    Mail::assertSent(NotificationMail::class);
});

it('не повторяет инфраструктурную ошибку раньше паузы backoff', function (): void {
    $notification = Notification::factory()->push()->create([
        'status' => NotificationStatus::Error->value,
        'attempts' => 1,
    ]);

    pollerFixTimestamps($notification->id, '2026-11-06 10:00:00+00:00');
    pollerAttemptAt(
        $notification->id,
        1,
        'error',
        '2026-11-06 10:00:00+00:00',
        'infrastructure',
        NotificationErrorCode::SEND_FAILED,
    );

    Carbon::setTestNow(Carbon::parse('2026-11-06 10:04:59', 'UTC'));
    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $notification->refresh();

    expect($notification->status)->toBe(NotificationStatus::Error)
        ->and($notification->attempts)->toBe(1)
        ->and(PushMessage::query()->count())->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-11-06 10:05:01', 'UTC'));
    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $notification->refresh();

    expect($notification->status)->toBe(NotificationStatus::Sent)
        ->and($notification->attempts)->toBe(2)
        ->and(PushMessage::query()->count())->toBe(1);
});

it('помечает уведомление необработанным после исчерпания попыток', function (): void {
    config([
        'notifications.sender.max_attempts' => 2,
        'notifications.sender.backoff_seconds' => [1],
    ]);

    $notification = Notification::factory()->push()->create();
    pollerFixTimestamps($notification->id, '2026-11-06 10:00:00+00:00');
    Schema::rename('push_messages', 'push_messages_unavailable');

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $notification->refresh();

    expect($notification->status)->toBe(NotificationStatus::Error)
        ->and($notification->attempts)->toBe(1);

    Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:02', 'UTC'));
    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $notification->refresh();

    expect($notification->status)->toBe(NotificationStatus::Failed)
        ->and($notification->attempts)->toBe(2)
        ->and($notification->last_error_code)->toBe(NotificationErrorCode::SEND_FAILED);

    $this->assertDatabaseHas('notification_attempts', [
        'notification_id' => $notification->id,
        'attempt' => 2,
        'status' => 'failed',
        'error_class' => 'infrastructure',
        'error_code' => NotificationErrorCode::SEND_FAILED,
    ]);

    Schema::rename('push_messages_unavailable', 'push_messages');
});

it('не повторяет бизнес-ошибку и сохраняет причину в журнале попыток', function (): void {
    $notification = Notification::factory()->create(['recipient_id' => 999]);

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $notification->refresh();

    expect($notification->status)->toBe(NotificationStatus::Failed)
        ->and($notification->attempts)->toBe(1)
        ->and($notification->last_error_code)->toBe(NotificationErrorCode::RECIPIENT_UNKNOWN);

    $this->assertDatabaseHas('notification_attempts', [
        'notification_id' => $notification->id,
        'attempt' => 1,
        'status' => 'failed',
        'error_class' => 'business',
        'error_code' => NotificationErrorCode::RECIPIENT_UNKNOWN,
    ]);

    expect((string) NotificationAttempt::query()->value('error_message'))->not->toBe('');

    Carbon::setTestNow(Carbon::parse('2026-11-07 10:00:00', 'UTC'));
    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $notification->refresh();

    expect($notification->attempts)->toBe(1)
        ->and(NotificationAttempt::query()->count())->toBe(1);
});

it('не блокирует остальные уведомления отравленной строкой', function (): void {
    $poisoned = Notification::factory()->create(['recipient_id' => 999]);
    $healthy = Notification::factory()->push()->create(['recipient_id' => 55]);

    $this->artisan('notifications:send', ['--once' => true, '--json' => true])
        ->expectsOutputToContain('"processed":2')
        ->assertExitCode(0);

    $poisoned->refresh();
    $healthy->refresh();

    expect($poisoned->status)->toBe(NotificationStatus::Failed)
        ->and($healthy->status)->toBe(NotificationStatus::Sent)
        ->and(PushMessage::query()->count())->toBe(1);
});

it('не блокирует остальные уведомления строкой с неизвестным каналом', function (): void {
    // Строка испорчена в обход модели: канала нет, отправка даёт бизнес-ошибку.
    $poisoned = Notification::factory()->create(['recipient_id' => 999]);
    DB::table('notifications')->where('id', $poisoned->id)->update(['channel' => 'pigeon']);
    $healthy = Notification::factory()->push()->create(['recipient_id' => 55]);

    $this->artisan('notifications:send', ['--once' => true, '--json' => true])
        ->expectsOutputToContain('"processed":2')
        ->assertExitCode(0);

    $poisoned->refresh();

    expect($poisoned->status)->toBe(NotificationStatus::Failed)
        ->and($poisoned->last_error_code)->toBe(NotificationErrorCode::PROGRAM_ERROR)
        ->and($healthy->refresh()->status)->toBe(NotificationStatus::Sent)
        ->and(PushMessage::query()->count())->toBe(1);
});

it('повторяет уведомление, брошенное в состоянии queued после паузы retry_after', function (): void {
    pollerContact();
    config(['notifications.sender.retry_after_seconds' => 1800]);

    $stale = Notification::factory()->create(['recipient_id' => 7, 'status' => 'queued', 'attempts' => 1]);
    pollerFixTimestamps($stale->id, now()->utc()->subSeconds(1801)->format('Y-m-d H:i:sP'));

    $fresh = Notification::factory()->create(['recipient_id' => 7, 'status' => 'queued', 'attempts' => 1]);
    pollerFixTimestamps($fresh->id, now()->utc()->subSeconds(60)->format('Y-m-d H:i:sP'));

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $stale->refresh();
    $fresh->refresh();

    expect($stale->status)->toBe(NotificationStatus::Sent)
        ->and($fresh->status)->toBe(NotificationStatus::Queued);
});

it('не берёт строку раньше плановой задержки next_attempt_at', function (): void {
    $notification = Notification::factory()->push()->create([
        'status' => NotificationStatus::Error->value,
        'attempts' => 0,
    ]);

    DB::table('notifications')->where('id', $notification->id)->update([
        'next_attempt_at' => '2026-11-06 10:00:30+00:00',
    ]);

    Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:29', 'UTC'));
    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    $notification->refresh();

    expect($notification->status)->toBe(NotificationStatus::Error)
        ->and($notification->attempts)->toBe(0);

    Carbon::setTestNow(Carbon::parse('2026-11-06 10:00:31', 'UTC'));
    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    expect($notification->refresh()->status)->toBe(NotificationStatus::Sent)
        ->and($notification->attempts)->toBe(1);
});

it('ограничивает пачку ключом --limit', function (): void {
    $first = Notification::factory()->push()->create();
    $second = Notification::factory()->push()->create();

    $this->artisan('notifications:send', ['--once' => true, '--limit' => '1'])->assertExitCode(0);

    $first->refresh();
    $second->refresh();

    expect($first->status)->toBe(NotificationStatus::Sent)
        ->and($second->status)->toBe(NotificationStatus::Created)
        ->and($second->attempts)->toBe(0);
});

it('отправляет одно уведомление по ключу --id', function (): void {
    $notification = Notification::factory()->push()->create();

    $this->artisan('notifications:send', ['--id' => (string) $notification->id])->assertExitCode(0);

    $notification->refresh();

    expect($notification->status)->toBe(NotificationStatus::Sent)
        ->and($notification->attempts)->toBe(1);
});

it('возвращает код 1, если уведомление по ключу --id не найдено', function (): void {
    $this->artisan('notifications:send', ['--id' => '999999'])->assertExitCode(1);
});

it('не блокирует email и push отказом Telegram в одной пачке', function (): void {
    pollerContact();

    $client = new FakeTelegramBotClient;
    $client->failure = new TelegramApiException('API Telegram ответил ошибкой.', 502, 'Bad Gateway');
    app()->instance(TelegramBotClient::class, $client);

    ChannelBinding::factory()->create([
        'user_id' => 7,
        'channel' => 'telegram',
        'address' => '777',
        'verified_at' => now()->utc(),
        'revoked_at' => null,
    ]);

    $email = Notification::factory()->create(['recipient_id' => 7]);
    $push = Notification::factory()->push()->create(['recipient_id' => 7]);
    $telegram = Notification::factory()->telegram()->create(['recipient_id' => 7]);

    $this->artisan('notifications:send', ['--once' => true])->assertExitCode(0);

    expect($email->refresh()->status)->toBe(NotificationStatus::Sent)
        ->and($push->refresh()->status)->toBe(NotificationStatus::Sent)
        ->and($telegram->refresh()->status)->toBe(NotificationStatus::Error)
        ->and($telegram->last_error_code)->toBe(NotificationErrorCode::TELEGRAM_API_FAILED)
        ->and(PushMessage::query()->count())->toBe(1);
});
